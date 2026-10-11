<?php

namespace Tests\Feature\ApiDocs;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * Checks a real API answer against the API reference (the OpenAPI document), in two ways:
 *
 *  1. the answer must be VALID for the documented shape: every required field present, every value of the documented type;
 *  2. the answer must not contain fields the reference does not mention (a shape that is valid but incomplete).
 *
 * Together they mean the reference describes what the API really sends, not what somebody once believed it sends.
 */
class ReferenceCheck
{
    private const ID = 'https://reference.test/openapi.json';

    /** @var array<string, mixed>|null the generated document, built once per test run (it takes a second or two) */
    private static ?array $document = null;

    /** The same document as JSON text: the form the validator reads, in which {} and [] are still different things. */
    private static string $json = '';

    /** @return array<string, mixed> */
    public static function document(): array
    {
        if (self::$document === null) {
            self::$json = json_encode(app(Generator::class)(Scramble::getGeneratorConfig(Scramble::DEFAULT_API)), JSON_THROW_ON_ERROR);
            self::$document = json_decode(self::$json, true, flags: JSON_THROW_ON_ERROR);
        }

        return self::$document;
    }

    /** @return list<string> everything wrong with the answer; empty when it matches the reference */
    public static function problems(TestResponse $response, string $method, string $path): array
    {
        $document = self::document();
        $status = (string) $response->getStatusCode();
        $operation = $document['paths'][$path][strtolower($method)] ?? throw new RuntimeException("The reference has no {$method} {$path}.");
        $documented = $operation['responses'][$status] ?? null;

        if ($documented === null) {
            return ["{$method} {$path} answered {$status}, which the reference does not list (it lists ".implode(', ', array_keys($operation['responses'])).').'];
        }

        // Shared answers (the usual 401, 403, 404, 422...) are described once and pointed at from each endpoint.
        $location = ['paths', $path, strtolower($method), 'responses', $status];
        if (isset($documented['$ref'])) {
            $location = explode('/', ltrim($documented['$ref'], '#/'));
            $documented = self::resolve($documented, $document);
        }

        if ($response->getContent() === '') {
            return isset($documented['content']) ? ["{$method} {$path} {$status}: the reference promises a body but the answer was empty."] : [];
        }

        $schema = $documented['content']['application/json']['schema'] ?? null;
        if ($schema === null) {
            return ["{$method} {$path} {$status}: the answer has a JSON body but the reference documents none."];
        }

        $data = json_decode($response->getContent(), false, flags: JSON_THROW_ON_ERROR);
        $problems = [];

        // 1. Valid for the documented shape.
        $validator = new Validator;
        $validator->setMaxErrors(8);
        $validator->resolver()->registerRaw(self::$json, self::ID);
        $pointer = implode('/', array_map(self::escape(...), [...$location, 'content', 'application/json', 'schema']));
        $result = $validator->validate($data, self::ID.'#/'.$pointer);

        if ($result->hasError()) {
            foreach ((new ErrorFormatter)->format($result->error(), false) as $where => $message) {
                $problems[] = "{$method} {$path} {$status}: at ".($where === '/' ? 'the top' : $where).": {$message}";
            }
        }

        // 2. Nothing undocumented.
        foreach (self::undocumented($data, $schema, $document, '') as $field) {
            $problems[] = "{$method} {$path} {$status}: the answer has \"{$field}\", which the reference does not mention.";
        }

        return array_values(array_unique($problems));
    }

    /**
     * Fields present in the data but absent from the documented shape.
     *
     * @return list<string> paths such as "data[].inventory.stock"
     */
    private static function undocumented(mixed $data, array $schema, array $document, string $at): array
    {
        $schema = self::resolve($schema, $document);

        // "one of these shapes": take the first one whose basic kind fits the data.
        foreach (['anyOf', 'oneOf'] as $choice) {
            if (isset($schema[$choice])) {
                foreach ($schema[$choice] as $option) {
                    if (self::kindFits($data, self::resolve($option, $document))) {
                        return self::undocumented($data, $option, $document, $at);
                    }
                }

                return [];
            }
        }

        $found = [];
        foreach ($schema['allOf'] ?? [] as $part) {
            // Parts of an "all of these" share one object; a field is documented if any part mentions it.
            $schema = array_replace_recursive($schema, ['properties' => (self::resolve($part, $document)['properties'] ?? [])]);
        }

        if (is_object($data) && isset($schema['properties'])) {
            $freeForm = ($schema['additionalProperties'] ?? false) !== false;

            foreach (get_object_vars($data) as $key => $value) {
                $here = ltrim("{$at}.{$key}", '.');

                if (! array_key_exists($key, $schema['properties'])) {
                    if (! $freeForm) {
                        $found[] = $here;
                    }

                    continue;
                }

                array_push($found, ...self::undocumented($value, $schema['properties'][$key], $document, $here));
            }
        } elseif (is_array($data) && isset($schema['items'])) {
            foreach ($data as $item) {
                array_push($found, ...self::undocumented($item, $schema['items'], $document, "{$at}[]"));
            }
        }

        return array_values(array_unique($found));
    }

    private static function resolve(array $schema, array $document): array
    {
        while (isset($schema['$ref'])) {
            $schema = data_get($document, str_replace('/', '.', ltrim($schema['$ref'], '#/')))
                ?? throw new RuntimeException("The reference points at {$schema['$ref']}, which does not exist.");
        }

        return $schema;
    }

    private static function kindFits(mixed $data, array $schema): bool
    {
        $types = (array) ($schema['type'] ?? (isset($schema['properties']) ? 'object' : (isset($schema['items']) ? 'array' : [])));

        return match (true) {
            $types === [] => true,
            is_object($data) => in_array('object', $types, true),
            is_array($data) => in_array('array', $types, true),
            is_null($data) => in_array('null', $types, true),
            is_bool($data) => in_array('boolean', $types, true),
            is_int($data) => in_array('integer', $types, true) || in_array('number', $types, true),
            is_float($data) => in_array('number', $types, true),
            default => in_array('string', $types, true),
        };
    }

    /** A JSON pointer segment, then made safe for a URI fragment ("{order}" contains characters a URI may not). */
    private static function escape(string $segment): string
    {
        return rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $segment));
    }
}
