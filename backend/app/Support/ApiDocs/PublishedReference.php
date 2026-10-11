<?php

namespace App\Support\ApiDocs;

use Dedoc\Scramble\CacheableGenerator;
use Dedoc\Scramble\GeneratorConfig;
use Dedoc\Scramble\GeneratorResult;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\ProNudge\ProNudgeCollector;

/**
 * Where the API reference shown at /docs/api comes from.
 *
 * On a developer's machine it is worked out from the code on every visit, so it is always current. That takes a couple
 * of seconds and tens of megabytes, which a small server should not spend on a documentation page, so anywhere else the
 * published copy (openapi.json) is served instead. That file is the same document: a test fails whenever it and the code
 * disagree, so what a server shows is exactly what was checked.
 */
class PublishedReference extends CacheableGenerator
{
    public function generate(GeneratorConfig $config): GeneratorResult
    {
        $file = base_path('openapi.json');

        if (app()->environment('local', 'testing') || ! is_file($file)) {
            return parent::generate($config);
        }

        $published = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        return new class($published) extends GeneratorResult
        {
            /** @param  array<mixed, mixed>  $published */
            public function __construct(private readonly array $published)
            {
                parent::__construct(new OpenApi('3.1.0'), collect(), new ProNudgeCollector);
            }

            public function spec(): array
            {
                return $this->published;
            }
        };
    }
}
