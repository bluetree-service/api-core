<?php

declare(strict_types=1);

namespace Bluetree\Mod\Hello;

use Bluetree\Api\Core\Helpers\Auth;

/**
 * Refuses every restricted route. An application replaces it with its own
 * class in main.auth.auth_class; until then nothing behind "restricted": true
 * is reachable, which is the safe way round.
 */
class HelloAuth extends Auth
{
    public function checkAuth(array $route): bool
    {
        return false;
    }
}
