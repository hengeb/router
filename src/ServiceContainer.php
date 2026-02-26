<?php
/**
 * @author Henrik Gebauer <code@henrik-gebauer.de>
 * @license https://creativecommons.org/publicdomain/zero/1.0/ CC0 1.0
 */

declare(strict_types=1);
namespace Hengeb\Router;

use Hengeb\Db\Db;
use Hengeb\Router\Interface\CurrentUserInterface;
use Hengeb\Router\Router;
use Hengeb\Router\LatteExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Service container
 */
abstract class ServiceContainer {
    /** @var object[] */
    protected array $instances = [];

    /** @var callable[] */
    protected array $knownServices = [];

    public abstract function getCurrentUser(): CurrentUserInterface;

    public function __construct()
    {
        $this->registerDefaultServices();
    }

    protected function registerDefaultServices()
    {
        $this
        ->registerService(\Latte\Engine::class, function(self $container): \Latte\Engine {
            $latte = new \Latte\Engine;
            $latte->setTempDirectory('/tmp/latte');
            $latte->setLoader(new \Latte\Loaders\FileLoader($container->getTemplatesDir()));
            $latte->addExtension(new \Latte\Bridges\Tracy\TracyExtension);
            $latte->addExtension($container->getService(LatteExtension::class));
            return $latte;
        })
        ->registerService(LatteExtension::class, function (self $container): LatteExtension {
            $extension = new LatteExtension($container->getRouter(), $container->getCurrentUser());
            return $extension;
        })
        ->registerService(Db::class, fn(): Db => new Db([
            'host' => getenv('MYSQL_HOST') ?: 'localhost',
            'port' => getenv('MYSQL_PORT') ?: 3306,
            'user' => getenv('MYSQL_USER'),
            'password' => getenv('MYSQL_PASSWORD'),
            'database' => getenv('MYSQL_DATABASE') ?: 'database',
        ]))
        ;
    }

    protected function createService(string $class, ?callable $setup = null): object
    {
        return $this->instances[$class] ??= ($setup ? $setup($this) : new $class);
    }

    public function registerService(string $class, ?callable $setup = null): static
    {
        $this->knownServices[$class] = $setup ?? fn() => new $class;
        return $this;
    }

    public function getService(string $class): object
    {
        if (!isset($this->instances[$class])) {
            if (isset($this->knownServices[$class])) {
                $this->instances[$class] = $this->knownServices[$class]($this);
            } else {
                $basename = basename(str_replace('\\', '/', $class));
                if (method_exists($this, 'get' . $basename)) {
                    $this->instances[$class] = $this->{'get' . $basename}();
                }
            }
        }
        return $this->instances[$class] ?: throw new \OutOfBoundsException('cannot create unknown service ' . $class);
    }

    public function getControllerDir(): string
    {
        $class = get_class($this);
        return dirname((new \ReflectionClass($class))->getFileName()) . '/Controller';
    }

    public function getTemplatesDir(): string
    {
        return '/var/www/templates';
    }

    public function getRequest(): Request
    {
        return $this->createService(Request::class, function() {
            $request = Request::createFromGlobals();
            $request->setSession(new Session());
            return $request;
        });
    }

    public function getRouter(): Router
    {
        return $this->createService(Router::class, function ($container) {
            $router = new Router($container->getControllerDir());
            $router->addServiceContainer($container);
            return $router;
        });
    }

    public function startDebugger(): static
    {
        \Tracy\Debugger::enable(str_ends_with(getenv('DOMAINNAME'), 'localhost') ? \Tracy\Debugger::Development : \Tracy\Debugger::Production);
        return $this;
    }

    public function dispatchRequest(): void
    {
        $this->getRouter()->dispatch($this->getRequest(), $this->getCurrentUser())->send();
    }
}
