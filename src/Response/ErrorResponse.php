<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Response;

class ErrorResponse extends BaseResponse
{
    public const string STATUS_BAD_REQUEST = 'bad_request';
    public const string STATUS_UNAUTHORIZED = 'unauthorized';
    public const string STATUS_FORBIDDEN = 'forbidden';
    public const string STATUS_NOT_FOUND = 'not_found';
    public const string STATUS_INTERNAL_ERROR = 'internal_error';
    public const string STATUS_FAILED = 'failed';

    public function responseNotFound(string $message, ?array $details = null): array
    {
        $this->scheme['code'] = 404;
        $this->scheme['status'] = self::STATUS_NOT_FOUND;
        $this->scheme['message'] = $message;
        $this->scheme['details'] = $details;

        return $this->scheme;
    }

    public function responseInternalError(string $message, ?array $details = null): array
    {
        $this->scheme['code'] = 500;
        $this->scheme['status'] = self::STATUS_INTERNAL_ERROR;
        $this->scheme['message'] = $message;
        $this->scheme['details'] = $details;

        return $this->scheme;
    }

    public function responseForbidden(string $message, ?array $details = null): array
    {
        $this->scheme['code'] = 403;
        $this->scheme['status'] = self::STATUS_FORBIDDEN;
        $this->scheme['message'] = $message;
        $this->scheme['details'] = $details;

        return $this->scheme;
    }

    public function responseUnauthorized(string $message, ?array $details = null): array
    {
        $this->scheme['code'] = 401;
        $this->scheme['status'] = self::STATUS_UNAUTHORIZED;
        $this->scheme['message'] = $message;
        $this->scheme['details'] = $details;

        return $this->scheme;
    }
}
