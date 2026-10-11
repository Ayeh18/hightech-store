<?php

namespace App\Http\Requests\PcBuilder;

use App\Enums\BuildPriority;
use App\Enums\BuildPurpose;
use App\Enums\TargetResolution;
use App\Services\Recommendations\BuildBrief;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** "Suggest a PC for me": a budget and what the PC is for. Everything else has a sensible default. */
class RecommendBuildRequest extends FormRequest
{
    public const MIN_BUDGET_SEN = 50_000;        // RM 500

    public const MAX_BUDGET_SEN = 10_000_000;    // RM 100,000

    public const MAX_GAMES = 5;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // In sen, like every amount: 400000 is RM 4,000.00.
            'budget' => ['required', 'integer', 'min:'.self::MIN_BUDGET_SEN, 'max:'.self::MAX_BUDGET_SEN],
            'purpose' => ['required', Rule::enum(BuildPurpose::class)],
            'priority' => ['sometimes', Rule::enum(BuildPriority::class)],
            'resolution' => ['sometimes', Rule::enum(TargetResolution::class)],
            // Titles the customer plays. Only an AI recommender reads them, as a hint; plain words only.
            'games' => ['sometimes', 'array', 'max:'.self::MAX_GAMES],
            'games.*' => ['string', 'max:40', 'regex:/^[\pL\pN][\pL\pN \-:\'.&+!]*$/u', 'distinct:ignore_case'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'budget.min' => 'Enter a budget of at least RM 500.',
            'budget.max' => 'Enter a budget of at most RM 100,000.',
            'games.*.regex' => 'Game titles can only contain letters, numbers and simple punctuation.',
        ];
    }

    public function brief(): BuildBrief
    {
        return new BuildBrief(
            Money::fromSen($this->integer('budget')),
            $this->enum('purpose', BuildPurpose::class),
            $this->enum('priority', BuildPriority::class) ?? BuildPriority::Balanced,
            $this->enum('resolution', TargetResolution::class) ?? TargetResolution::FullHd,
            array_values(array_map(fn ($title) => trim((string) $title), (array) $this->validated('games', []))),
        );
    }
}
