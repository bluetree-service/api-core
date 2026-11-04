<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Response;

abstract class BaseResponse
{
    protected array $scheme = [
        'code' => null,
        'status' =>  null,
        'message' => null,
        'details' => null,
    ];

    public function genericResponse(int $code, string $status, string $message, ?array $details = null): array
    {
        $this->scheme['code'] = $code;
        $this->scheme['status'] = $status;
        $this->scheme['message'] = $message;
        $this->scheme['details'] = $details;

        return $this->scheme;
    }

    public function clearResponse(): void
    {
        $this->scheme['code'] = null;
        $this->scheme['status'] = null;
        $this->scheme['message'] = null;
        $this->scheme['details'] = null;
    }

    /**
     * Origins allowed to send credentialed requests. Set by ApiCore from
     * main.cors.allowed_origins before any response is rendered.
     *
     * @var string[]
     */
    protected static array $allowedOrigins = [];

    /**
     * @param string[] $origins
     */
    public static function setAllowedOrigins(array $origins): void
    {
        self::$allowedOrigins = $origins;
    }

    /**
     * @throws \JsonException
     */
    public function render(): void
    {
        $this->sendCorsHeaders();
        // Answers depend on who is asking, so nothing between here and the
        // client may keep one; and a browser must not read one as anything else.
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header_remove('X-Powered-By');
        header('Content-Type: application/json', true, $this->scheme['code']);
        echo \json_encode($this->scheme, JSON_THROW_ON_ERROR);
        exit;
    }

    /**
     * Whether a request from this origin may carry credentials.
     *
     * Matching is exact: an origin is a scheme, host and port, and comparing
     * any less strictly would let a look-alike host read a signed-in session.
     */
    public static function isAllowedOrigin(string $origin): bool
    {
        return $origin !== '' && \in_array($origin, self::$allowedOrigins, true);
    }

    /**
     * A wildcard origin cannot be combined with credentials — browsers reject
     * the pair and drop the session cookie — so the request's own origin is
     * echoed back, and only when it is on the allow list.
     */
    protected function sendCorsHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        header('Vary: Origin');

        if (self::isAllowedOrigin($origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    }
}
