<?php

declare(strict_types=1);

// vendor/bluetree-service/api-core/pub when installed, the package's own
// checkout otherwise.
require_once \file_exists(__DIR__ . '/../../../autoload.php')
    ? __DIR__ . '/../../../autoload.php'
    : __DIR__ . '/../vendor/autoload.php';

use Bluetree\Api\Core\ApiCore;
use Bluetree\Api\Core\Health;
use Bluetree\Api\Core\Response\ErrorResponse;
use Bluetree\Api\Core\Response\SuccessResponse;

// A cache that does not answer stops ApiCore itself, which then answers 500 on
// its own; everything past this point has at least the configuration.
$core = new ApiCore();
$core->run();

$results = (new Health($core->returnConfigs()))->run();

// One status per check and nothing else: hosts, paths and exception messages
// stay with `app:health`, which only someone inside the container can run.
if (Health::failed($results)) {
    $response = new ErrorResponse();
    $response->genericResponse(
        503,
        ErrorResponse::STATUS_FAILED,
        'Something this installation depends on is not reachable.',
        Health::summary($results)
    );
} else {
    $response = new SuccessResponse();
    $response->genericResponse(
        200,
        SuccessResponse::STATUS_SUCCESS,
        'Everything answered.',
        Health::summary($results)
    );
}

$response->render();
