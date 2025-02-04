<?php

declare(strict_types=1);

namespace Kami\Cocktail\OpenAPI;

use OpenApi\Attributes as OAT;
use Kami\Cocktail\OpenAPI\Schemas\APIError;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class NotFoundResponse extends OAT\Response
{
    public function __construct()
    {
        parent::__construct(response: 404, description: 'Resource record not found.', content: [
            new OAT\JsonContent(properties: [new OAT\Property(property: 'data', type: 'object', ref: APIError::class)]),
        ], headers: [
            new OAT\Header(header: 'x-ratelimit-limit', description: 'Max number of attempts.', schema: new OAT\Schema(type: 'integer')),
            new OAT\Header(header: 'x-ratelimit-remaining', description: 'Remaining number of attempts.', schema: new OAT\Schema(type: 'integer')),
        ]);
    }
}
