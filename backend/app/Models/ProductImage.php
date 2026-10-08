<?php

namespace App\Models;

use Database\Factories\ProductImageFactory;
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

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Files on the local disk are returned as same-origin paths ("/storage/..."), so they work behind the
     * Vite proxy, nginx or any domain. Remote disks (e.g. S3) already return a full URL and are left alone.
     */
    public function getUrlAttribute(): string
    {
        $url = Storage::disk('public')->url($this->path);
        $app = rtrim((string) config('app.url'), '/');

        return str_starts_with($url, $app.'/') ? substr($url, strlen($app)) : $url;
    }
}
