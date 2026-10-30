<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Tests;

use Bluetree\Api\Core\Response\BaseResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BaseResponse::class)]
class CorsOriginTest extends TestCase
{
    protected function setUp(): void
    {
        BaseResponse::setAllowedOrigins(['https://bluetree.pl', 'http://localhost:8081']);
    }

    protected function tearDown(): void
    {
        BaseResponse::setAllowedOrigins([]);
    }

    public function testAllowsAListedOrigin(): void
    {
        self::assertTrue(BaseResponse::isAllowedOrigin('https://bluetree.pl'));
        self::assertTrue(BaseResponse::isAllowedOrigin('http://localhost:8081'));
    }

    #[DataProvider('rejectedOrigins')]
    public function testRejects(string $origin, string $why): void
    {
        self::assertFalse(BaseResponse::isAllowedOrigin($origin), $why);
    }

    public static function rejectedOrigins(): array
    {
        return [
            'a request with no origin' => ['', 'an empty origin must not match'],
            'a different site' => ['https://zlosliwy.example', 'an unlisted origin must not match'],
            'the same host over plain http' => ['http://bluetree.pl', 'the scheme is part of the origin'],
            'a different port' => ['http://localhost:5173', 'the port is part of the origin'],
            'a subdomain' => ['https://evil.bluetree.pl', 'a subdomain is a different origin'],
            'a look-alike suffix' => ['https://bluetree.pl.evil.com', 'a suffix match would be a hole'],
            'a trailing slash' => ['https://bluetree.pl/', 'an origin carries no path'],
            'different case' => ['https://BLUETREE.pl', 'matching is exact'],
        ];
    }

    public function testAllowsNothingWhenTheListIsEmpty(): void
    {
        BaseResponse::setAllowedOrigins([]);

        self::assertFalse(BaseResponse::isAllowedOrigin('https://bluetree.pl'));
    }
}
