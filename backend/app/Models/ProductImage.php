<?php

namespace App\Models;

use Database\Factories\ProductImageFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['product_id', 'path', 'alt', 'sort_order', 'is_primary'])]
class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    // Every photo gets a new random name and is never changed, so browsers and the store's CDN may keep it for a year.
    public const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The disk the photos live on (IMAGE_DISK): this server's own "public" disk, or an S3-compatible store. */
    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('shop.image_disk'));
    }

    /**
     * Files on the local disk are returned as same-origin paths ("/storage/..."), so they work behind the
     * Vite proxy, nginx or any domain. Remote disks (e.g. S3) already return a full URL and are left alone.
     */
    public function getUrlAttribute(): string
    {
        $url = self::disk()->url($this->path);
        $app = rtrim((string) config('app.url'), '/');

        return str_starts_with($url, $app.'/') ? substr($url, strlen($app)) : $url;
    }
}
