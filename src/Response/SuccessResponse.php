<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Response;

class SuccessResponse extends BaseResponse
{
    public const string STATUS_SUCCESS = 'success';

    public function responseNoContent(): array
    {
        $this->scheme['code'] = 204;
        $this->scheme['status'] = self::STATUS_SUCCESS;
        $this->scheme['message'] = '';

        return $this->scheme;
    }
}
