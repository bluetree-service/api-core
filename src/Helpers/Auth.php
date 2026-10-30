<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Helpers;

abstract class Auth
{
    public function __construct(
        protected $logger,
        protected $cache,
        protected $register,
        protected $event,
        protected $config,
        protected $request
    ) {

    }

    /**
     * The auth object is built during ApiCore's constructor, when only main.json
     * has been read. Module configs, connections and routes arrive later, so the
     * complete set is handed over before any request is authorised — the same
     * arrangement Modules uses.
     */
    public function setFinalConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * @param array $route the matched route, including the module it belongs to
     */
    abstract public function checkAuth(array $route): bool;

    /**
     * The session the last checkAuth() resolved, or null when it resolved none.
     *
     * It is what separates a request with no usable session from one whose
     * session is fine and simply may not have this module.
     */
    public function session(): ?array
    {
        return null;
    }
}
