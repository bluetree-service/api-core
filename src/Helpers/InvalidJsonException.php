<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Helpers;

/**
 * The request body is not what the client said it was. ApiCore answers 400.
 */
class InvalidJsonException extends \JsonException
{
}
