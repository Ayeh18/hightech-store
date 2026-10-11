<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Who suggests a PC for a budget
    |--------------------------------------------------------------------------
    | "rules": the shop's own, fixed rules (app/Services/Recommendations/RuleBasedRecommender). Free, instant, needs no
    |          account, and always gives the same answer for the same catalog.
    | "ai":    an AI service is asked first. Whatever it proposes is checked like any other build (real products, in
    |          stock, compatible, within budget). If it is unavailable, over its limit or proposes something unusable,
    |          the rules are used instead, so a customer always gets an answer.
    */
    'driver' => env('RECOMMENDER_DRIVER', 'rules'),

    /*
    | Any service that speaks the "OpenAI chat completions" format, which most do: Groq, GitHub Models, OpenRouter,
    | Google Gemini's compatibility address, or a model on your own computer (Ollama). Nothing is sent to it except the
    | brief (budget, purpose, screen, priority, game titles) and a list of products with their prices and specifications.
    | No customer data ever leaves the shop.
    */
    'ai' => [
        'base_url' => rtrim((string) env('AI_BASE_URL', 'https://api.groq.com/openai/v1'), '/'),
        'api_key' => env('AI_API_KEY'),
        // The model's name as the service lists it. There is no default on purpose: services rename and retire models.
        'model' => env('AI_MODEL'),
        'timeout_seconds' => (int) env('AI_TIMEOUT', 20),

        // Free services allow a limited number of requests. After this many in one day the shop stops asking and uses
        // its own rules until tomorrow, instead of showing customers errors. 0 means no limit.
        'daily_limit' => (int) env('AI_DAILY_LIMIT', 200),

        // The same brief against the same catalog and prices gets the same answer from memory for this long.
        'cache_minutes' => (int) env('AI_CACHE_MINUTES', 60),

        // How many products per slot the AI is shown, spread from cheap to dear. More costs more of the daily allowance.
        'candidates_per_slot' => 10,
    ],
];
