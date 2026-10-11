<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Copies every product photo from one disk to another, for the day the shop moves its photos (for example from this server's
 * own disk to an S3-compatible store). The database keeps only each photo's path, and the path is the same on both disks, so
 * after copying you only change IMAGE_DISK.
 *
 * It only ever copies: nothing is deleted from the disk it reads, and a photo already on the destination is left alone, so it
 * is safe to run twice or to stop halfway.
 */
class CopyImages extends Command
{
    protected $signature = 'shop:copy-images {from : The disk the photos are on now, e.g. public} {to : The disk to copy them to, e.g. s3}';

    protected $description = 'Copy product photos from one disk to another (nothing is deleted)';

    public function handle(): int
    {
        [$from, $to] = [(string) $this->argument('from'), (string) $this->argument('to')];

        foreach ([$from, $to] as $name) {
            if (! is_array(config("filesystems.disks.{$name}"))) {
                $this->error("\"{$name}\" is not a disk this shop knows. Use \"public\" or \"s3\".");

                return self::FAILURE;
            }
        }

        if ($from === $to) {
            $this->error('The two disks are the same: there is nothing to copy.');

            return self::FAILURE;
        }

        [$copied, $already, $missing, $failed] = [0, 0, 0, 0];

        foreach (ProductImage::query()->orderBy('id')->lazy() as $image) {
            try {
                if (Storage::disk($to)->exists($image->path)) {
                    $already++;
                } elseif (! Storage::disk($from)->exists($image->path)) {
                    $missing++;
                    $this->warn("Not on \"{$from}\": {$image->path} (photo #{$image->id})");
                } else {
                    $written = Storage::disk($to)->writeStream($image->path, Storage::disk($from)->readStream($image->path), ['CacheControl' => ProductImage::CACHE_CONTROL]);
                    throw_unless($written, RuntimeException::class, 'the disk refused the file');
                    $copied++;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("Could not copy {$image->path}: ".str($e->getMessage())->before("\n"));
            }
        }

        $this->info("Copied {$copied}. Already there: {$already}. Missing from \"{$from}\": {$missing}. Failed: {$failed}.");

        if ($failed === 0 && $missing === 0) {
            $this->line("Every photo is on \"{$to}\". Set IMAGE_DISK={$to} to start using it.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
