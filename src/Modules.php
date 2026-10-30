<?php

declare(strict_types=1);

namespace Bluetree\Api\Core;

use BlueFilesystem\StaticObjects\Structure;
use SimpleLog\Log;
use BlueEvent\Event\Base\EventDispatcher;
use Bluetree\Api\Core\Helpers\Json;
use Symfony\Component\HttpFoundation\Request;

class Modules
{
    protected array $moduleDirectories = [];
    public function __construct(
        protected Log $logger,
        protected $cache,
        protected $register,
        protected EventDispatcher $event,
        protected array $config,
        protected Request $request,
    ) {
        $this->readModDirectory();
    }

    public function runModules(): void
    {
        foreach ($this->moduleDirectories as $path) {
            $moduleName = \basename($path);
            $mainClassPath = "\\Bluetree\\Mod\\$moduleName\\Main";
            if (\class_exists($mainClassPath)) {
                $this->register->factory($mainClassPath);
                if ($this->config['main']['debug'] === true) {
                    $this->logger->debug("Executed main class for module: $moduleName");
                }
            } else {
                $this->logger->warning("Main class for module don't exists: $mainClassPath");
            }
        }
    }

    /**
     * @param array $config
     * @return void
     */
    public function setFinalConfig(array $config): void
    {
        $this->config = $config;
    }

    protected function readModDirectory(): void
    {
        $structure = new Structure(ApiCore::modulesDir());
        $this->moduleDirectories = $structure->returnPaths()['dir'] ?? [];
    }

    /**
     * Record which module a route came from. Route configs are merged into one
     * flat list, so without this the owning module is lost by the time a request
     * is authorised against it.
     */
    protected function stampRoutesWithModule(array $routeConfig, string $moduleName): array
    {
        foreach ($routeConfig['routes'] ?? [] as $route => $definition) {
            $routeConfig['routes'][$route]['module'] = $moduleName;
        }

        return $routeConfig;
    }

    /**
     * @throws \JsonException
     */
    public function loadConfig(): array
    {
        $configs = [];

        foreach ($this->moduleDirectories as $index => $path) {
            if ($this->config['main']['debug'] === true) {
                $this->logger->debug("Loading module from path: $path");
            }

            $this->event->triggerEvent('module_loaded', ['path' => $path]);
            $moduleName = \basename($path);

            if (Structure::exist($path . '/config/main.json')) {
                $configs['modules'][$moduleName] = Json::readFile($path . '/config/main.json');
            } else {
                $this->logger->warning("Main configuration for module don't exists: $path");
                unset($this->moduleDirectories[$index]);
                continue;
            }

            foreach (ApiCore::SUPPORTED_OTHER_CONFIGS as $configName) {
                if (Structure::exist($path . "/config/$configName.json")) {
                    if ($this->config['main']['debug'] === true) {
                        $this->logger->debug("Loading $configName configuration for module from path: $path");
                    }
                    $otherConfig = Json::readFile($path . "/config/$configName.json");

                    if ($configName === 'routes') {
                        $otherConfig = $this->stampRoutesWithModule($otherConfig, $moduleName);
                    }

                    $configs = \array_replace_recursive($configs, $otherConfig);
                } else {
                    $this->logger->warning("$configName configuration for module don't exists: $path");
                }
            }
        }

        return $configs;
    }
}
