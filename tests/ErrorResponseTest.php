<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Tests;

use Bluetree\Api\Core\Response\ErrorResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorResponse::class)]
class ErrorResponseTest extends TestCase
{
    /**
     * The two refusals must not share a code: the front end reads 401 as the
     * session having run out and sends the user to log in, so a module the user
     * simply may not reach has to answer something else — otherwise the login
     * page finds the session still good and sends them straight back.
     */
    public function testForbiddenIsNotUnauthorized(): void
    {
        $forbidden = (new ErrorResponse())->responseForbidden('nope');

        self::assertSame(403, $forbidden['code']);
        self::assertSame(ErrorResponse::STATUS_FORBIDDEN, $forbidden['status']);
        self::assertNotSame(401, $forbidden['code']);
    }
}
