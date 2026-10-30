<?php

declare(strict_types=1);

// vendor/bluetree-service/api-core/pub when installed, the package's own
// checkout otherwise.
require_once \file_exists(__DIR__ . '/../../../autoload.php')
    ? __DIR__ . '/../../../autoload.php'
    : __DIR__ . '/../vendor/autoload.php';

Bluetree\Api\Core\Health::respond();
