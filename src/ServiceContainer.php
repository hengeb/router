<?php
/**
 * @author Henrik Gebauer <code@henrik-gebauer.de>
 * @license https://creativecommons.org/publicdomain/zero/1.0/ CC0 1.0
 */

declare(strict_types=1);
namespace Hengeb\Router;

use Hengeb\Router\Router;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Service container
 */
class ServiceContainer {
    protected array $instances = [];

    protected function createService(string $classname, ?callable $setup = null): object
    {
        return $this->instances[$classname] ??= ($setup ? $setup() : new $classname);
    }

    public function getService(string $class): object
    {
        $class = basename(str_replace('\\', '/', $class));
        if (method_exists($this, 'get' . $class)) {
            return $this->{'get' . $class}();
        } else {
            throw new \OutOfBoundsException('cannot create unknown service ' . $class);
        }
    }

    public function getRequest(): Request
    {
        return $this->createService(Request::class, function () {
            $request = Request::createFromGlobals();
            $request->setSession(new Session());
            return $request;
        });
    }

    public function getControllerDir(): string
    {
        $class = get_class($this);
        return dirname((new \ReflectionClass($class))->getFileName()) . '/Controller';
    }

    public function getRouter(): Router
    {
        return $this->createService(Router::class, function () {
            $router = new Router($this->getControllerDir());
            $router->addServiceContainer($this);
            return $router;
        });
    }
}
