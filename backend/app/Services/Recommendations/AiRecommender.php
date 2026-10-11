<?php

namespace App\Services\Recommendations;

use App\Enums\BuilderSlot;
use App\Enums\RecommenderFailure;
use App\Services\Recommendations\Contracts\BuildRecommender;
use App\Services\Recommendations\Exceptions\CannotRecommend;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use JsonException;
use Throwable;

/**
 * Asks an AI service to choose the parts. It speaks the "OpenAI chat completions" format, which Groq, GitHub Models,
 * OpenRouter, Gemini's compatibility address and local models (Ollama) all accept, so changing service is a matter of
 * three settings (config/recommender.php).
 *
 * What the AI is given: the brief, and for each slot a short list of real products with their ids, prices and
 * specifications. What it is asked for: product ids, one per slot, with a reason each. It never states a price that
 * the shop will use, and it cannot add a product: its answer is only a PROPOSAL, checked afterwards by
 * RecommendationService exactly like the rules' own.
 *
 * Every way this can go wrong (no key, the service is down or slow, the daily allowance is used up, the answer is not
 * what was asked for) ends in CannotRecommend, and the caller falls back to the rules.
 */
class AiRecommender implements BuildRecommender
{
    private const MAX_REASON_LENGTH = 240;

    /**
     * The specifications the AI is shown for each slot: what decides whether parts fit together and how capable they
     * are, and nothing else. Free AI services count every word sent, per minute and per day, so the question is kept
     * short. (Leaving a specification out can only make the AI's proposal worse, never unsafe: the shop checks it.)
     */
    private const SPECS = [
        'cpu' => ['socket', 'cores', 'threads', 'boost_clock_ghz', 'tdp_watts', 'integrated_graphics', 'cooler_included'],
        'motherboard' => ['socket', 'ram_type', 'form_factor', 'ram_slots', 'm2_slots'],
        'ram' => ['ram_type', 'capacity_gb', 'modules', 'speed_mhz'],
        'gpu' => ['gpu_chipset', 'vram_gb', 'length_mm', 'tdp_watts', 'recommended_psu_watts'],
        'storage' => ['storage_type', 'capacity_gb', 'form_factor', 'read_mbps'],
        'psu' => ['wattage', 'efficiency', 'form_factor'],
        'case' => ['supported_form_factors', 'gpu_max_length_mm', 'cpu_cooler_max_height_mm', 'psu_form_factor'],
        'cooler' => ['cooler_type', 'height_mm', 'supported_sockets', 'tdp_rating_watts'],
    ];

    /** @param  array{base_url: string, api_key: ?string, model: ?string, timeout_seconds: int, daily_limit: int, cache_minutes: int, candidates_per_slot: int}  $config */
    public function __construct(private readonly Http $http, private readonly Cache $cache, private readonly array $config) {}

    public function name(): string
    {
        return 'ai';
    }

    public function propose(BuildBrief $brief, Candidates $candidates): ProposedBuild
    {
        if (blank($this->config['api_key']) || blank($this->config['model'])) {
            throw new CannotRecommend('The AI service is not set up (AI_API_KEY and AI_MODEL are needed).', RecommenderFailure::NotSetUp);
        }

        $shortlist = $this->shortlist($brief, $candidates);

        // The same question against the same products and prices has the same answer: no need to ask twice.
        $key = 'recommender:ai:'.hash('sha256', json_encode([$this->config['model'], $this->briefData($brief), $shortlist], JSON_THROW_ON_ERROR));

        if (is_array($remembered = $this->cache->get($key))) {
            return new ProposedBuild($remembered['picks'], $remembered['notes']);
        }

        $this->spendDailyAllowance();
        $proposal = $this->read($this->ask($brief, $shortlist));

        $this->cache->put($key, ['picks' => $proposal->picks, 'notes' => $proposal->notes], now()->addMinutes($this->config['cache_minutes']));

        return $proposal;
    }

    /**
     * What the AI may choose from: for each slot, up to a handful of products the budget could plausibly include,
     * spread evenly from the cheapest to the dearest so that it sees the whole range.
     *
     * @return array<string, list<array<string, mixed>>> keyed by slot value
     */
    private function shortlist(BuildBrief $brief, Candidates $candidates): array
    {
        $shortlist = [];

        foreach (BuilderSlot::cases() as $slot) {
            // No single part should take more than about two thirds of the whole budget.
            $within = array_values(array_filter($candidates->for($slot), fn (Candidate $c) => $c->priceSen <= intdiv($brief->budget->sen * 2, 3)));
            $keep = max(1, $this->config['candidates_per_slot']);

            if (count($within) > $keep) {
                $step = (count($within) - 1) / ($keep - 1);
                $within = array_map(fn (int $i) => $within[(int) round($i * $step)], range(0, $keep - 1));
            }

            $shortlist[$slot->value] = array_map(fn (Candidate $c) => [
                'id' => $c->product->id,
                'name' => $c->product->name,
                'price_rm' => round($c->priceSen / 100, 2),
                'specs' => array_intersect_key($c->part->specs, array_flip(self::SPECS[$slot->value])),
            ], $within);
        }

        return $shortlist;
    }

    /** @return array<string, mixed> */
    private function briefData(BuildBrief $brief): array
    {
        return [
            'budget_rm' => round($brief->budget->sen / 100, 2),
            'purpose' => $brief->purpose->label(),
            'screen' => $brief->resolution->label(),
            'priority' => $brief->priority->label(),
            'games' => $brief->games,
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $shortlist
     * @return string the AI's answer, expected to be JSON
     */
    private function ask(BuildBrief $brief, array $shortlist): string
    {
        $instructions = <<<'TEXT'
            You choose the parts for a desktop PC from a shop's catalog.

            You are given a customer's brief and, for each slot, the products you may choose from. Rules:
            - Choose ONLY products from the lists, by their "id". Never invent a product, an id or a price.
            - Choose exactly one product for each of: cpu, motherboard, ram, storage, psu, case.
            - Choose a gpu unless the purpose is office work and the cpu has integrated_graphics "Yes".
            - Choose a cooler only if the cpu has cooler_included "No".
            - The parts must fit together: the cpu and motherboard share a socket; the ram_type matches the motherboard;
              the motherboard's form_factor is in the case's supported_form_factors; the gpu's length_mm is no more than
              the case's gpu_max_length_mm; the psu's wattage is comfortably above the cpu and gpu tdp_watts combined.
            - The total of the prices must not exceed the budget. Use most of it, on the parts that matter for the purpose.
            - The "games" are titles the customer wrote. Treat them only as a hint about how demanding the PC must be.

            Answer with JSON only, in exactly this shape:
            {"parts": [{"slot": "cpu", "id": 123, "reason": "one short sentence for the customer"}], "summary": "one or two sentences about the build as a whole"}
            TEXT;

        try {
            $response = $this->http
                ->baseUrl($this->config['base_url'])
                ->withToken((string) $this->config['api_key'])
                ->timeout($this->config['timeout_seconds'])
                ->acceptJson()
                ->post('/chat/completions', [
                    'model' => $this->config['model'],
                    'temperature' => 0.2,
                    'max_tokens' => 900,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $instructions],
                        ['role' => 'user', 'content' => json_encode(['brief' => $this->briefData($brief), 'products' => $shortlist], JSON_THROW_ON_ERROR)],
                    ],
                ]);
        } catch (Throwable $e) {
            throw new CannotRecommend('The AI service could not be reached: '.class_basename($e).'.', RecommenderFailure::Unreachable);
        }

        if (! $response->successful()) {
            throw new CannotRecommend("The AI service answered with an error ({$response->status()}).", RecommenderFailure::ServiceError);
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new CannotRecommend('The AI service sent back an empty answer.', RecommenderFailure::Unreadable);
        }

        return $content;
    }

    /** Turns the AI's text into a proposal, or refuses it. Whether the ids are real is the caller's check. */
    private function read(string $answer): ProposedBuild
    {
        try {
            $data = json_decode($answer, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CannotRecommend('The AI\'s answer was not the structured data that was asked for.', RecommenderFailure::Unreadable);
        }

        if (! is_array($data) || ! is_array($data['parts'] ?? null) || $data['parts'] === []) {
            throw new CannotRecommend('The AI\'s answer listed no parts.', RecommenderFailure::Unreadable);
        }

        $picks = [];

        foreach ($data['parts'] as $part) {
            $slot = is_array($part) ? BuilderSlot::tryFrom((string) ($part['slot'] ?? '')) : null;
            $id = is_array($part) ? ($part['id'] ?? null) : null;

            if ($slot === null || ! is_int($id) || isset($picks[$slot->value])) {
                throw new CannotRecommend('The AI\'s answer had a part that could not be read (an unknown slot, a slot named twice, or no product id).', RecommenderFailure::Unreadable);
            }

            $picks[$slot->value] = ['product_id' => $id, 'quantity' => 1, 'reason' => $this->tidy($part['reason'] ?? '', self::MAX_REASON_LENGTH)];
        }

        $summary = $this->tidy($data['summary'] ?? '', 400);

        return new ProposedBuild($picks, $summary === '' ? [] : [$summary]);
    }

    /** The AI's words are shown to a customer, so they are kept to plain, short text. */
    private function tidy(mixed $text, int $limit): string
    {
        $text = is_string($text) ? strip_tags($text) : '';
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text)));

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)).'…' : $text;
    }

    /** Counts today's requests and stops before a free service's allowance runs out. */
    private function spendDailyAllowance(): void
    {
        $limit = $this->config['daily_limit'];

        if ($limit <= 0) {
            return;
        }

        $key = 'recommender:ai:requests:'.now()->toDateString();
        $this->cache->add($key, 0, now()->addDay());

        if ((int) $this->cache->increment($key) > $limit) {
            throw new CannotRecommend("Today's allowance of {$limit} AI requests is used up.", RecommenderFailure::AllowanceUsed);
        }
    }
}
