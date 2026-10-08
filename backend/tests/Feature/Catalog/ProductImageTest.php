<?php

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
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
