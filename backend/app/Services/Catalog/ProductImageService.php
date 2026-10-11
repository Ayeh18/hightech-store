<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stores product photos safely: the upload is decoded and re-encoded as WebP, which strips metadata
 * and anything hidden inside the file, and the stored name is random (never the client's filename).
 */
class ProductImageService
{
    public const MAX_IMAGES = 8;

    private const MAX_EDGE_PX = 1600;

    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Product $product, UploadedFile $file, ?string $alt, bool $makePrimary, User $actor): ProductImage
    {
        if ($product->images()->count() >= self::MAX_IMAGES) {
            throw ValidationException::withMessages(['image' => 'A product can have at most '.self::MAX_IMAGES.' images.']);
        }

        $path = "products/{$product->id}/".Str::ulid().'.webp';
        $binary = $this->toWebp($file); // an unusable file is refused before anything is written

        try {
            ProductImage::disk()->put($path, $binary, ['CacheControl' => ProductImage::CACHE_CONTROL]);
        } catch (Throwable $e) {
            report($e);
            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'The photo could not be saved to storage. Please try again in a moment.');
        }

        try {
            return DB::transaction(function () use ($product, $path, $alt, $makePrimary, $actor) {
                $isFirst = ! $product->images()->exists();
                $primary = $makePrimary || $isFirst;

                if ($primary) {
                    $product->images()->update(['is_primary' => false]);
                }

                $image = $product->images()->create([
                    'path' => $path,
                    'alt' => $alt ?? $product->name,
                    'is_primary' => $primary,
                    'sort_order' => (int) $product->images()->max('sort_order') + 1,
                ]);

                $this->audit->log('product.image_added', $actor, $product, null, ['image_id' => $image->id]);

                return $image;
            });
        } catch (Throwable $e) {
            $this->forget($path); // the database refused it: do not leave the file behind
            throw $e;
        }
    }

    public function delete(ProductImage $image, User $actor): void
    {
        DB::transaction(function () use ($image, $actor) {
            $product = $image->product;
            $wasPrimary = $image->is_primary;
            $image->delete();

            if ($wasPrimary) {
                $product->images()->first()?->update(['is_primary' => true]);
            }

            $this->audit->log('product.image_removed', $actor, $product, ['image_id' => $image->id]);
        });

        // The row is gone for good; only now remove the file. If the store is down, an unused file is left behind, which
        // costs nothing, whereas a row pointing at a missing file would show a broken picture.
        $this->forget($image->path);
    }

    /** Removes a stored file, and never fails the request over it: the worst case is an unused file. */
    private function forget(string $path): void
    {
        try {
            ProductImage::disk()->delete($path);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function toWebp(UploadedFile $file): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw ValidationException::withMessages(['image' => 'The file is not a valid image.']);
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::MAX_EDGE_PX / max($width, $height));

        if ($scale < 1) {
            $source = imagescale($source, (int) round($width * $scale), (int) round($height * $scale)) ?: $source;
        }

        imagepalettetotruecolor($source);
        imagesavealpha($source, true);

        ob_start();
        $ok = imagewebp($source, null, 85);
        $binary = (string) ob_get_clean();

        if (! $ok || $binary === '') {
            throw new RuntimeException('Unable to encode the image.');
        }

        return $binary;
    }
}
