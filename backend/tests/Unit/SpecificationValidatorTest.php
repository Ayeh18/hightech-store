<?php

use App\Models\Category;
use App\Models\SpecDefinition;
use App\Services\Catalog\SpecificationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function caseCategory(): Category
{
    $category = Category::factory()->create();
    SpecDefinition::factory()->for($category)->list(['ATX', 'Micro ATX', 'Mini ITX'])->create(['key' => 'form_factors']);
    SpecDefinition::factory()->for($category)->number('mm')->create(['key' => 'gpu_max_length_mm']);
    SpecDefinition::factory()->for($category)->create(['key' => 'note', 'is_required' => false]);

    return $category->load('specDefinitions');
}

it('converts valid specs into the column that matches each type', function () {
    $rows = (new SpecificationValidator)->normalize(caseCategory(), [
        'form_factors' => ['ATX', 'ATX', 'Mini ITX'],
        'gpu_max_length_mm' => '320',
    ]);

    expect($rows['form_factors'])->toBe(['value_string' => null, 'value_number' => null, 'value_json' => ['ATX', 'Mini ITX']])
        ->and($rows['gpu_max_length_mm'])->toBe(['value_string' => null, 'value_number' => 320.0, 'value_json' => null])
        ->and($rows)->not->toHaveKey('note');
});

it('reports every problem at once, keyed by spec', function () {
    try {
        (new SpecificationValidator)->normalize(caseCategory(), ['form_factors' => ['EATX'], 'bogus' => 1]);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toEqualCanonicalizing(['specs.form_factors', 'specs.gpu_max_length_mm', 'specs.bogus']);
    }
});
