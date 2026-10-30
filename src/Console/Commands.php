<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutput;
use BlueContainer\Container;
use BlueRegister\Register;
use BlueRegister\RegisterException;
use DateInterval;
use Bluetree\Api\Core\ApiCore;

enum ConsoleCommands
{
    case Cache;
    case Config;
    case Healthcheck;
}

class Commands extends Container
{
    /**
     * @var Register
     */
    protected Register $register;

    public function __construct()
    {
        $this->register = new Register([]);

        try {
            parent::__construct(['data' => $this->readAllCommandTools()]);
        } catch (\Exception $exception) {
            $message = $exception->getMessage();
            $line = $exception->getLine();
            $file = $exception->getFile();
            echo "$message \nLine: $line \nFile: $file";
        }
    }

    /**
     * @return array
     * @throws \Exception
     */
    protected function readAllCommandTools(): array
    {
        $list = [];
        $namespaces = [];

        $namespaces['file_list'] = \glob(ApiCore::modulesDir() . '*/Console/*Command.php');

        foreach (ConsoleCommands::cases() as $commandCase) {
            $namespaces['file_list'][] = ApiCore::BASE_CORE_DIR . 'Console/Commands/' . $commandCase->name . '.php';
        }

        foreach ($namespaces['file_list'] as $commandFile) {
            $namespaces['list'][$commandFile] = $this->resolveToolNamespace($commandFile);
        }

        foreach ($namespaces['file_list'] as $commandFile) {
            $namespace = $namespaces['list'][$commandFile];
            $object = $this->registerCommandTool($namespace);

            if ($object !== null) {
                $list[$namespace] = $object;
            }
        }

        return $list;
    }

    /**
     * @param string $path
     * @return string
     */
    protected function resolveToolNamespace(string $path): string
    {
        $gettingClass = false;
        $gettingNamespace = false;
        $namespace = '';
        $class = '';
        $contents = \file_get_contents($path);

        foreach (\token_get_all($contents) as $token) {
            $isArray = \is_array($token);

            if ($isArray && $token[0] === \T_NAMESPACE) {
                $gettingNamespace = true;
            }

            if ($isArray && $token[0] === \T_CLASS) {
                $gettingClass = true;
            }

            $namespace = $this->getNamespaceToken($token, $namespace, $gettingNamespace, $isArray);
            if ($isArray) {
                $class = $this->getClassToken($token, $class, $gettingClass);
            }

            if ($class) {
                break;
            }
        }

        return $namespace ? $namespace . '\\' . $class : $class;
    }

    /**
     * @param array|string $token
     * @param string $namespace
     * @param bool $gettingNamespace
     * @param bool $isArray
     * @return string
     */
    protected function getNamespaceToken($token, string $namespace, bool &$gettingNamespace, bool $isArray): string
    {
        $tokens = [\T_STRING, \T_NS_SEPARATOR];

        if (\PHP_VERSION_ID >= 80000) {
            $tokens[] = T_NAME_QUALIFIED;
        }

        if ($gettingNamespace === true) {
            if ($isArray && \in_array($token[0], $tokens, true)) {
                $namespace .= $token[1];
            } elseif ($token === ';') {
                $gettingNamespace = false;
            }
        }

        return $namespace;
    }

    /**
     * @param array $token
     * @param string $class
     * @param bool $gettingClass
     * @return string
     */
    protected function getClassToken(array $token, string $class, bool $gettingClass): string
    {
        if ($gettingClass === true && $token[0] === T_STRING) {
            $class = $token[1];
        }

        return $class;
    }

    /**
     * @param string $namespace
     * @return Command|null
     */
    protected function registerCommandTool(string $namespace): ?Command
    {
        try {
            return $this->register->factory(
                $namespace,
            );
        } catch (RegisterException $exception) {
            (new ConsoleOutput())->writeln('<error>' . $exception->getMessage() . '</error>');
        }

        return null;
    }
}
