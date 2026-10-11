<?php

namespace App\Support\ApiDocs;

use App\Support\Money;
use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Types as OpenApi;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;

/**
 * Tells the API reference what a Money value looks like in an answer. The tool reads classes, not what their
 * jsonSerialize() returns, so without this every price would be documented as an empty object.
 *
 * Keep it in step with Money::jsonSerialize(); tests/Feature/ApiDocs checks real answers against it.
 */
class MoneyToSchema extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && $type->isInstanceOf(Money::class);
    }

    public function toSchema(Type $type): OpenApi\Type
    {
        return (new OpenApi\ObjectType)
            ->addProperty('amount', (new OpenApi\IntegerType)->setDescription('The amount in sen (RM 1 = 100 sen).')->example(119900))
            ->addProperty('currency', (new OpenApi\StringType)->example(Money::CURRENCY))
            ->addProperty('formatted', (new OpenApi\StringType)->setDescription('The amount written for people.')->example('RM 1,199.00'))
            ->setRequired(['amount', 'currency', 'formatted']);
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('schemas', 'Money', $this->components);
    }
}
