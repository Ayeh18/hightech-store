<?php

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('stores an uploaded image as webp under a random name and marks the first one primary', function () {
    $product = Product::factory()->create();

    $response = $this->actingAs($this->admin)->post("/api/products/{$product->id}/images", [
        'image' => UploadedFile::fake()->image('my photo (1).PNG', 800, 600),
        'alt' => 'Front view',
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.is_primary', true)->assertJsonPath('data.alt', 'Front view');

    $image = ProductImage::findOrFail($response->json('data.id'));
    expect($image->path)->toMatch('#^products/'.$product->id.'/[0-9A-Z]{26}\.webp$#');
    Storage::disk('public')->assertExists($image->path);
    expect(Storage::disk('public')->get($image->path))->toStartWith('RIFF');
});

it('lets a newer upload become primary on request and promotes another when the primary is deleted', function () {
    $product = Product::factory()->create();
    $post = fn (array $extra = []) => $this->actingAs($this->admin)->post("/api/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('a.jpg', 400, 400), ...$extra], ['Accept' => 'application/json']);

    $first = $post()->json('data.id');
    $second = $post(['is_primary' => true])->json('data.id');

    expect(ProductImage::find($first)->is_primary)->toBeFalse()->and(ProductImage::find($second)->is_primary)->toBeTrue();

    $this->actingAs($this->admin)->deleteJson("/api/products/{$product->id}/images/{$second}")->assertNoContent();

    expect(ProductImage::find($second))->toBeNull()->and(ProductImage::find($first)->is_primary)->toBeTrue();
});

it('exposes the primary image url on product cards', function () {
    $product = Product::factory()->create();
    $this->actingAs($this->admin)->post("/api/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('a.jpg', 400, 400)], ['Accept' => 'application/json']);

    $this->getJson('/api/products')->assertJsonPath('data.0.image', fn ($url) => str_starts_with($url, "/storage/products/{$product->id}/"));
});

it('rejects files that are not safe images', function (callable $file) {
    $product = Product::factory()->create();

    $this->actingAs($this->admin)->post("/api/products/{$product->id}/images", ['image' => $file()], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    expect(ProductImage::count())->toBe(0);
})->with([
    'php script' => [fn () => UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET["c"]);')],
    'php disguised as jpg' => [fn () => UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["c"]);')],
    'svg' => [fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
    'too large' => [fn () => UploadedFile::fake()->image('big.jpg', 800, 800)->size(4096)],
    'too small' => [fn () => UploadedFile::fake()->image('tiny.jpg', 50, 50)],
]);

it('limits the number of images per product', function () {
    $product = Product::factory()->create();
    ProductImage::factory()->count(8)->for($product)->create();

    $this->actingAs($this->admin)->post("/api/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('a.jpg', 400, 400)], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('image');
});

it('cannot delete an image through a different product', function () {
    $mine = Product::factory()->create();
    $other = Product::factory()->create();
    $image = ProductImage::factory()->for($other)->create();

    $this->actingAs($this->admin)->deleteJson("/api/products/{$mine->id}/images/{$image->id}")->assertNotFound();
    expect(ProductImage::find($image->id))->not->toBeNull();
});

it('forbids customers from uploading images', function () {
    $product = Product::factory()->create();
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $this->actingAs($customer)->post("/api/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('a.jpg', 400, 400)], ['Accept' => 'application/json'])->assertForbidden();
});

/** Points the shop's photos at an S3-compatible store ("s3"), faked here by a folder on this computer. */
function useRemotePhotoStore(): void
{
    config(['shop.image_disk' => 's3', 'filesystems.disks.s3.url' => 'https://photos.example.com']);
    Storage::fake('s3', ['url' => 'https://photos.example.com']); // a fake disk does not read the real one's address
}

it('keeps photos on the disk chosen by IMAGE_DISK and serves them from the store\'s own address', function () {
    useRemotePhotoStore();
    $product = Product::factory()->create();

    $response = $this->actingAs($this->admin)->post("/api/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('a.jpg', 400, 400)], ['Accept' => 'application/json'])->assertCreated();

    $image = ProductImage::findOrFail($response->json('data.id'));
    Storage::disk('s3')->assertExists($image->path);
    Storage::disk('public')->assertMissing($image->path);
    expect($response->json('data.url'))->toBe('https://photos.example.com/'.$image->path);
    $this->getJson('/api/products')->assertJsonPath('data.0.image', 'https://photos.example.com/'.$image->path);
});

it('tells browsers and the store\'s CDN they may keep a photo for a year, since its name never gets reused', function () {
    config(['shop.image_disk' => 's3']);
    $store = Mockery::mock(Filesystem::class);
    $store->shouldReceive('put')->once()->withArgs(fn ($path, $contents, $options) => $options === ['CacheControl' => 'public, max-age=31536000, immutable'] && str_starts_with($contents, 'RIFF'));
    $store->shouldReceive('url')->andReturn('https://photos.example.com/a.webp');
    Storage::set('s3', $store);
    $product = Product::factory()->create();

    $this->actingAs($this->admin)->post("/api/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('a.jpg', 400, 400)], ['Accept' => 'application/json'])->assertCreated();
});

it('says so, and records no photo, when the store cannot be written to', function () {
    config(['shop.image_disk' => 's3']);
    $store = Mockery::mock(Filesystem::class);
    $store->shouldReceive('put')->andThrow(new RuntimeException('the bucket is unreachable'));
    Storage::set('s3', $store);
    $product = Product::factory()->create();

    $this->actingAs($this->admin)->post("/api/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('a.jpg', 400, 400)], ['Accept' => 'application/json'])
        ->assertStatus(503)
        ->assertJsonPath('message', 'The photo could not be saved to storage. Please try again in a moment.');

    expect(ProductImage::count())->toBe(0);
});

it('does not leave the file behind when the database refuses the photo', function () {
    $this->mock(AuditLogger::class)->shouldReceive('log')->andThrow(new RuntimeException('database trouble'));
    $product = Product::factory()->create();

    $this->actingAs($this->admin)->post("/api/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('a.jpg', 400, 400)], ['Accept' => 'application/json'])->assertStatus(500);

    expect(ProductImage::count())->toBe(0)->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('removes the stored file when a photo is deleted', function () {
    $product = Product::factory()->create();
    $id = $this->actingAs($this->admin)->post("/api/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('a.jpg', 400, 400)], ['Accept' => 'application/json'])->json('data.id');
    $path = ProductImage::findOrFail($id)->path;
    Storage::disk('public')->assertExists($path);

    $this->actingAs($this->admin)->deleteJson("/api/products/{$product->id}/images/{$id}")->assertNoContent();

    Storage::disk('public')->assertMissing($path);
});

it('still deletes the photo when the store cannot delete the file (an unused file is harmless, a broken picture is not)', function () {
    config(['shop.image_disk' => 's3']);
    $store = Mockery::mock(Filesystem::class);
    $store->shouldReceive('delete')->andThrow(new RuntimeException('the bucket is unreachable'));
    Storage::set('s3', $store);
    $image = ProductImage::factory()->for(Product::factory()->create(), 'product')->create();

    $this->actingAs($this->admin)->deleteJson("/api/products/{$image->product_id}/images/{$image->id}")->assertNoContent();

    expect(ProductImage::find($image->id))->toBeNull();
});
