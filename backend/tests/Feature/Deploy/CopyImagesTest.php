<?php

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('s3');
    $this->product = Product::factory()->create();
});

function photoOn(string $disk, Product $product, string $contents = 'RIFF-photo'): ProductImage
{
    $image = ProductImage::factory()->for($product)->create();
    Storage::disk($disk)->put($image->path, $contents);

    return $image;
}

it('copies every photo to the other disk under the same path, and deletes nothing', function () {
    $first = photoOn('public', $this->product, 'RIFF-one');
    $second = photoOn('public', $this->product, 'RIFF-two');

    $this->artisan('shop:copy-images', ['from' => 'public', 'to' => 's3'])
        ->expectsOutputToContain('Copied 2. Already there: 0. Missing from "public": 0. Failed: 0.')
        ->expectsOutputToContain('Set IMAGE_DISK=s3 to start using it.')
        ->assertSuccessful();

    expect(Storage::disk('s3')->get($first->path))->toBe('RIFF-one')
        ->and(Storage::disk('s3')->get($second->path))->toBe('RIFF-two');
    Storage::disk('public')->assertExists([$first->path, $second->path]);
});

it('can be run twice: a photo already on the destination is left exactly as it is', function () {
    $image = photoOn('public', $this->product, 'RIFF-new-copy');
    Storage::disk('s3')->put($image->path, 'RIFF-already-there');

    $this->artisan('shop:copy-images', ['from' => 'public', 'to' => 's3'])->expectsOutputToContain('Copied 0. Already there: 1.')->assertSuccessful();

    expect(Storage::disk('s3')->get($image->path))->toBe('RIFF-already-there');
});

it('names a photo whose file is missing, copies the rest, and does not claim everything moved', function () {
    $lost = ProductImage::factory()->for($this->product)->create();
    $kept = photoOn('public', $this->product);

    $this->artisan('shop:copy-images', ['from' => 'public', 'to' => 's3'])
        ->expectsOutputToContain("Not on \"public\": {$lost->path}")
        ->expectsOutputToContain('Copied 1. Already there: 0. Missing from "public": 1. Failed: 0.')
        ->doesntExpectOutputToContain('Set IMAGE_DISK=s3')
        ->assertSuccessful();

    Storage::disk('s3')->assertExists($kept->path);
});

it('reports a photo the destination refused and fails, so a half-finished move is never mistaken for a finished one', function () {
    $image = photoOn('public', $this->product);
    $store = Mockery::mock(Filesystem::class);
    $store->shouldReceive('exists')->andReturn(false);
    $store->shouldReceive('writeStream')->andThrow(new RuntimeException('Access Denied'));
    Storage::set('s3', $store);

    $this->artisan('shop:copy-images', ['from' => 'public', 'to' => 's3'])
        ->expectsOutputToContain("Could not copy {$image->path}: Access Denied")
        ->expectsOutputToContain('Failed: 1.')
        ->assertFailed();
});

it('refuses a disk that does not exist, or copying a disk onto itself', function (string $from, string $to, string $message) {
    photoOn('public', $this->product);

    $this->artisan('shop:copy-images', ['from' => $from, 'to' => $to])->expectsOutputToContain($message)->assertFailed();
})->with([
    'unknown destination' => ['public', 'dropbox', '"dropbox" is not a disk this shop knows'],
    'unknown source' => ['floppy', 's3', '"floppy" is not a disk this shop knows'],
    'same disk' => ['public', 'public', 'The two disks are the same'],
]);
