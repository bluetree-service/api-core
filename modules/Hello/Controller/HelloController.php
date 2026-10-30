<?php

declare(strict_types=1);

namespace Bluetree\Mod\Hello\Controller;

use Bluetree\Api\Core\Controller\BaseController;
use Bluetree\Api\Core\Response\SuccessResponse;

class HelloController extends BaseController
{
    public function index(): void
    {
        $response = new SuccessResponse();
        $response->genericResponse(200, SuccessResponse::STATUS_SUCCESS, 'Hello', ['module' => 'Hello']);
        $response->render();
    }
}
