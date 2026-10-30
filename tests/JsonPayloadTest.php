<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Tests;

use Bluetree\Api\Core\Helpers\Json;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(Json::class)]
class JsonPayloadTest extends TestCase
{
    public function testReadsFormFields(): void
    {
        $request = Request::create('/save', 'POST', ['month' => 'April 2026', 'brutto' => '124.50']);

        self::assertSame(['month' => 'April 2026', 'brutto' => '124.50'], Json::payload($request));
    }

    public function testReadsJsonBody(): void
    {
        $request = Request::create('/save', 'POST', [], [], [], [], '{"month":"April 2026","days":[{"time":"8"}]}');

        self::assertSame(
            ['month' => 'April 2026', 'days' => [['time' => '8']]],
            Json::payload($request)
        );
    }

    public function testPrefersFormFieldsWhenBothArePresent(): void
    {
        $request = Request::create('/save', 'POST', ['month' => 'from form'], [], [], [], '{"month":"from json"}');

        self::assertSame(['month' => 'from form'], Json::payload($request));
    }

    public function testEmptyRequestYieldsAnEmptyPayload(): void
    {
        self::assertSame([], Json::payload(Request::create('/save', 'POST')));
    }

    public function testMalformedJsonIsReportedRatherThanSilentlyDropped(): void
    {
        $request = Request::create('/save', 'POST', [], [], [], [], '{not json');

        $this->expectException(\JsonException::class);

        Json::payload($request);
    }
}
