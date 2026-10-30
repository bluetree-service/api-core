<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Controller;

use Bluetree\Api\Core\Response\SuccessResponse;

class OptionsController extends BaseController
{
    public function index(): void
    {
        $response = new SuccessResponse();
        $response->responseNoContent();
        $response->render();
    }
}
