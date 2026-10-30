<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Controller;

use Bluetree\Api\Core\Helpers\Validate;
use BlueRegister\Register;
use BlueCache\Cache;
use SimpleLog\Log;
use BlueEvent\Event\Base\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

abstract class BaseController
{
    protected Log $logger;
    protected Cache $cache;
    protected Register $register;
    protected EventDispatcher $event;
    protected array $config;
    protected Request $request;
    protected ?Validate $validator = null;

    public function __construct(
        $logger,
        $cache,
        $register,
        $event,
        $config,
        $request
    ) {
        $this->logger = $logger;
        $this->cache = $cache;
        $this->register = $register;
        $this->event = $event;
        $this->config = $config;
        $this->request = $request;
    }

    /**
     * Validator for module input classes, built on first use.
     */
    protected function validator(): Validate
    {
        return $this->validator ??= $this->register->factory(Validate::class);
    }
}
