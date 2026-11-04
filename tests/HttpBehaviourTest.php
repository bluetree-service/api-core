<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Tests;

use Bluetree\Api\Core\ApiCore;
use Bluetree\Api\Core\Response\BaseResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a client actually receives, over a socket: tests/fixtures/http-app.php
 * behind `php -S`.
 */
#[CoversClass(ApiCore::class)]
#[CoversClass(BaseResponse::class)]
class HttpBehaviourTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private int $port = 0;
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/api-core-http-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            \proc_terminate($this->server);
            \proc_close($this->server);
        }

        \array_map('unlink', \glob("$this->dir/*"));
        \rmdir($this->dir);
    }

    private function serve(bool $debug = false): void
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int)\substr(\strrchr(\stream_socket_get_name($socket, false), ':'), 1);
        \fclose($socket);

        $this->server = \proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$this->port", __DIR__ . '/fixtures/http-app.php'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['APP_DEBUG' => $debug ? '1' : '0', 'API_CORE_TEST_DIR' => $this->dir] + \getenv()
        );

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @\fsockopen('127.0.0.1', $this->port);

            if ($connection !== false) {
                \fclose($connection);

                return;
            }

            \usleep(50_000);
        }

        self::fail('the test server did not start');
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
     */
    private function call(string $method, string $path, array $headers = [], string $body = ''): array
    {
        if ($this->server === null) {
            $this->serve();
        }

        // Named, or the client assumes a form and PHP parses the body as one.
        $lines = ['Content-Type: application/json'];

        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }

        $answer = \file_get_contents("http://127.0.0.1:$this->port$path", false, \stream_context_create(['http' => [
            'method' => $method,
            'header' => $lines,
            'content' => $body,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]));

        $raw = \http_get_last_response_headers();
        $received = [];

        foreach (\array_slice($raw, 1) as $line) {
            [$name, $value] = \explode(':', $line, 2);
            $received[\strtolower($name)] = \trim($value);
        }

        return [
            'status' => (int)\explode(' ', $raw[0])[1],
            'headers' => $received,
            'body' => (string)$answer,
            'json' => \json_decode((string)$answer, true),
        ];
    }

    #[DataProvider('foreignWrites')]
    public function testWriteFromAnotherOriginIsRefused(string $origin, ?string $host): void
    {
        $headers = ['Origin' => $origin] + ($host === null ? [] : ['Host' => $host]);

        self::assertSame(403, $this->call('POST', '/write', $headers, '{"a":1}')['status']);
    }

    public static function foreignWrites(): array
    {
        return [
            'a sibling subdomain, which SameSite=Lax lets through' => ['https://poczta.bluetree.pl', 'api.bluetree.pl'],
            'an opaque origin, as from a sandboxed frame' => ['null', 'api.bluetree.pl'],
            'a name that only starts like this host' => ['https://gry.bluetree.pl.evil.example', 'gry.bluetree.pl'],
            'the same machine on another port' => ['http://localhost:9999', 'localhost:8092'],
            'an unrelated site' => ['https://evil.example', null],
        ];
    }

    #[DataProvider('acceptedWrites')]
    public function testWriteIsAccepted(array $headers): void
    {
        self::assertSame(200, $this->call('POST', '/write', $headers, '{"a":1}')['status']);
    }

    public static function acceptedWrites(): array
    {
        return [
            'a client without a browser sends no origin' => [[]],
            'an origin on the allow list' => [['Origin' => 'https://bluetree.pl']],
            'a page served from the same host as the api, as in gry' => [
                ['Origin' => 'https://gry.bluetree.pl', 'Host' => 'gry.bluetree.pl'],
            ],
        ];
    }

    public function testReadFromAnotherOriginAnswersButIsNotShared(): void
    {
        $answer = $this->call('GET', '/read', ['Origin' => 'https://evil.example']);

        self::assertSame(200, $answer['status']);
        self::assertArrayNotHasKey('access-control-allow-origin', $answer['headers']);
    }

    public function testMethodCannotBeOverriddenByAHeader(): void
    {
        $answer = $this->call('POST', '/write', ['X-HTTP-Method-Override' => 'DELETE'], '{"a":1}');

        self::assertSame(200, $answer['status']);
    }

    public function testRouteThatDoesNotSayIsRestricted(): void
    {
        self::assertSame(401, $this->call('GET', '/no-flag')['status']);
    }

    public function testPreflightStaysOpen(): void
    {
        self::assertSame(204, $this->call('OPTIONS', '/no-flag')['status']);
    }

    #[DataProvider('badBodies')]
    public function testBodyThatIsNotAJsonDocumentIsTheClientsFault(string $body): void
    {
        $answer = $this->call('POST', '/write', [], $body);

        self::assertSame(400, $answer['status']);
        self::assertSame('bad_request', $answer['json']['status']);
        self::assertFileDoesNotExist("$this->dir/critical.log", 'a bad request must not raise an alarm');
    }

    public static function badBodies(): array
    {
        return [
            'malformed' => ['{bad'],
            'a number' => ['5'],
            'a string' => ['"x"'],
            'null' => ['null'],
            'a boolean' => ['true'],
        ];
    }

    public function testValidBodyReachesTheController(): void
    {
        self::assertSame(['a' => 1], $this->call('POST', '/write', [], '{"a":1}')['json']['details']);
    }

    public function testFatalErrorTellsTheClientNothing(): void
    {
        $answer = $this->call('GET', '/fatal');

        self::assertSame(500, $answer['status']);
        self::assertIsArray($answer['json'], "not JSON, PHP printed something:\n{$answer['body']}");
        self::assertSame(['code', 'status', 'message', 'details'], \array_keys($answer['json']));
        self::assertSame(['error_id'], \array_keys($answer['json']['details']));
        self::assertStringNotContainsString('.php', $answer['body']);
    }

    public function testFatalErrorIsDescribedWhenDebugging(): void
    {
        $this->serve(debug: true);

        $details = $this->call('GET', '/fatal')['json']['details'];

        self::assertStringContainsString('memory', $details['error']);
        self::assertStringEndsWith('http-app.php', $details['file']);
    }

    /**
     * What a failing login would otherwise write down: the password, because it
     * is an argument of a function on the stack.
     */
    public function testExceptionTraceCarriesNoArguments(): void
    {
        $this->serve(debug: true);

        $answer = $this->call('GET', '/boom');

        self::assertSame(500, $answer['status']);
        self::assertStringContainsString('openDatabase', $answer['json']['details']['trace']);
        self::assertStringNotContainsString('S3cretPass', $answer['body']);
        self::assertStringNotContainsString('S3cretPass', (string)\file_get_contents("$this->dir/critical.log"));
    }

    public function testResponseIsNotSniffedCachedOrSigned(): void
    {
        $headers = $this->call('GET', '/read')['headers'];

        self::assertSame('nosniff', $headers['x-content-type-options'] ?? null);
        self::assertSame('no-store', $headers['cache-control'] ?? null);
        self::assertArrayNotHasKey('x-powered-by', $headers);
    }

    /**
     * Without it a shared cache could hand the answer given to one origin to
     * another, whichever of them asked first.
     */
    public function testAnswerVariesByOriginEvenWhenTheOriginIsNotAllowed(): void
    {
        self::assertSame('Origin', $this->call('GET', '/read', ['Origin' => 'https://evil.example'])['headers']['vary'] ?? null);
    }
}
