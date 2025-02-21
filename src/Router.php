<?php
declare(strict_types=1);
namespace Hengeb\Router;

use Hengeb\Router\AccessDeniedException;
use Hengeb\Router\Attribute\RequestValue;
use Hengeb\Router\Exception\InvalidCsrfTokenException;
use Hengeb\Router\Exception\InvalidRouteException;
use Hengeb\Router\Exception\InvalidUserDataException;
use Hengeb\Router\Exception\NotFoundException;
use Hengeb\Router\Exception\NotLoggedInException;
use Hengeb\Router\Interface\CurrentUserInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * @author Henrik Gebauer <code@henrik-gebauer.de>
 * @license https://opensource.org/license/mit MIT
 */

/**
 * Router
 */
class Router {
    /**
     * known types for dependency injection
     * [[string $type, callable $retriever, ?string $identifier], ...]
     */
    private array $types = [];

    /**
     * known services for dependency injection
     * [$className => callable $retriever, ...]
     */
    private array $services = [];

    private ?Request $request = null;
    private ?CurrentUserInterface $currentUser;

    private RouteMap $routeMap;
    private array $exceptionHandlers = [];
    private ?object $controller = null;
    private ?\Exception $exception = null;

    public function __construct(string $controllerDir)
    {
        $this->routeMap = new RouteMap($controllerDir);

        $this->addType('string', fn($v) => $v);
        $this->addType('mixed', fn($v) => $v);
        $this->addType('', fn($v) => $v);
        $this->addType('int', 'intval');
        $this->addType('bool', 'boolval');
        $this->addType('float', 'floatval');
        $this->addType(\DateTimeImmutable::class, fn($v) => new \DateTimeImmutable($v));
        $this->addType(\DateTime::class, fn($v) => new \DateTime($v));

        $this->addService(self::class, fn() => $this);
        $this->addService(Request::class, fn() => $this->request);
        $this->addService(ParameterBag::class, fn() => $this->request->getPayload());
        $this->addService(\Exception::class, fn() => $this->exception);
    }

    public function addType(string $type, callable $retriever, ?string $identifierName = null): void
    {
        $this->types[] = [$type, $retriever, $identifierName];
    }

    public function addService(string $class, object $objectOrRetriever): void
    {
        $this->services[$class] = $objectOrRetriever;
    }

    public function addExceptionHandler(string $exceptionClass, callable $handler): void
    {
        $this->exceptionHandlers[$exceptionClass] = $handler;
    }

    public function dispatch(Request $request, ?CurrentUserInterface $currentUser = null): Response {
        $this->request = $request;
        $this->currentUser = $currentUser;

        if ($currentUser) {
            $this->addService($currentUser::class, $this->currentUser);
            $this->addService(CurrentUserInterface::class, $this->currentUser);
        }

        try {
            foreach ($this->routeMap->getRoutes() as $matcher => [$httpMethod, $pathPattern, $queryInfo, $controller, $functionName, $conditions, $checkCsrfToken]) {
                if ($this->request->getMethod() !== $httpMethod || !preg_match($pathPattern, $this->request->getPathInfo(), $matches)) {
                    continue;
                }
                foreach ($queryInfo as $parameterName => $parameterPattern) {
                    if (!$this->request->query->has($parameterName) || !preg_match($parameterPattern, $this->request->query->getString($parameterName), $parameterMatches)) {
                        continue 2;
                    }
                    $matches += $parameterMatches;
                }

                $matches = $this->prepareMatches($matches);

                return $this->callConditionally($controller, $functionName, $matches, $conditions, checkCsrfToken: $checkCsrfToken);
            }
            throw new InvalidRouteException();
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function createCsrfToken(): string
    {
        if (!$this->request) {
            throw new \LogicException('cannot start session before calling Router::dispatch');
        }
        if (!$this->request->hasSession()) {
            $this->request->setSession(new Session());
        }
        $token = bin2hex(random_bytes(32));
        $csrfTokens = $this->request->getSession()->get('csrfTokens', []);
        $csrfTokens[$token] = time();
        // limit to a maximum of 50 tokens in the storage
        $csrfTokens = array_slice($csrfTokens, -50);
        $this->request->getSession()->set('csrfTokens', $csrfTokens);
        return $token;
    }

    private function addCsrfTokenHeader(Response $response): void
    {
        $response->headers->set('X-CSRF-Token', $this->createCsrfToken());
    }

    /**
     * @throws AccessDeniedException
     */
    private function checkCsrfToken(): void
    {
        $token = $this->request->getPayload()->get('_csrf_token');
        if (!$token) {
            $token = $this->request->headers->get('X-CSRF-Token');
        }
        $csrfTokens = $this->request->getSession()->get('csrfTokens', []);
        // invalidate tokens after 1 hour
        $csrfTokens = array_filter($csrfTokens, fn($time) => $time + 3600 > time());
        if (!isset($csrfTokens[$token])) {
            throw new InvalidCsrfTokenException();
        }
        unset($csrfTokens[$token]);
        $this->request->getSession()->set('csrfTokens', $csrfTokens);
    }

    private function handleException(\Exception $e): Response {
        $this->exception = $e;

        // stop possible output buffering (e.g. if the exception was thrown during the rendering of a template)
        ob_end_clean();

        if ($this->controller && method_exists($this->controller, 'handleException')) {
            return $this->callConditionally($this->controller, 'handleException');
        }

        foreach ($this->exceptionHandlers as $exceptionClass => $handler) {
            if (is_a($e::class, $exceptionClass, true)) {
                // $handler = [$className, $methodName]
                if (is_array($handler)) {
                    return $this->callConditionally($handler[0], $handler[1], arguments: [$e]);
                // closure
                } else {
                    $function = new \ReflectionFunction($handler);
                    $args = [$e, ...$this->injectDependencies($function, [], 1)];
                    return $function->invokeArgs($args);
                }
            }
        }
        return $this->defaultExceptionHandler($e);
    }

    private function defaultExceptionHandler(\Exception $e): Response {
        $header = ['Content-Type' => 'text/plain; charset=utf-8'];
        if ($e instanceof InvalidRouteException) {
            return new Response($e->getMessage() ?: 'path not found', 404, $header);
        } elseif ($e instanceof NotLoggedInException) {
            return new Response($e->getMessage() ?: 'login required', 401, $header);
        } elseif ($e instanceof NotFoundException) {
            return new Response($e->getMessage() ?: 'resource not found', 404, $header);
        } elseif ($e instanceof AccessDeniedException) {
            return new Response($e->getMessage() ?: 'permission required', 403, $header);
        } elseif ($e instanceof InvalidCsrfTokenException) {
            return new Response($e->getMessage() ?: 'request cannot be repeated', 400, $header);
        } elseif ($e instanceof InvalidUserDataException) {
            return new Response($e->getMessage() ?: 'submitted data is missing or invalid', 400, $header);
        } else {
            error_log($e->getMessage());
            error_log($e->getTraceAsString());
            $message = $e->getMessage() ?: 'internal error';
            if ($e->getCode()) {
                $message .= ' (code: ' . $e->getCode() . ')';
            }
            return new Response($message, 500, $header);
        }
    }

    private function prepareMatches(array $matches): array {
        $return = [];
        foreach ($matches as $name=>$match) {
            if (is_numeric($name)) {
                continue;
            }
            if (!str_contains($name, '__')) {
                $return[$name] = [null, $match];
            } else {
                [$attributeName, $identifierName] = explode('__', $name);
                $return[$attributeName] = [$identifierName, $match];
            }
        }
        return $return;
    }

    private function getModelRetriever(string $type, ?string $identifierName): callable {
        $best = null;

        foreach ($this->types as [$typeName, $retriever, $identifier]) {
            if ($typeName === $type && $identifier === $identifierName) {
                return $retriever;
            }
            if ($typeName === $type && $identifier === null) {
                $best = $retriever;
            }
        }
        if ($best) {
            return $best;
        }

        // default retriever: $type::getRepository()::getInstance()->{'findBy'.$identifierName}
        try {
            $repository = null;
            if (!method_exists($type, 'getRepository')) {
                throw new \Exception();
            }

            $repository = [$type, 'getRepository']()::getInstance();

            // findOneById, findOneByUsername etc.
            $tryMethods = [
                'findOneBy' . $identifierName, 'getOneBy' . $identifierName,
                'findBy' . $identifierName, 'getBy' . $identifierName,
                'findOne', 'getOne',
                'find', 'get',
            ];

            foreach ($tryMethods as $methodName) {
                $retriever = [$repository, $methodName];
                if (method_exists(...$retriever)) {
                    $type = (string)(new \ReflectionObject($repository))->getMethod($methodName)->getParameters()[0]->getType();
                    return ($type === 'int') ? (fn($id) => $retriever(intval($id))) : $retriever;
                }
            }

            // findOneBy('id', ...), getOneBy('username', ...) etc.
            foreach (['findOneBy', 'getOneBy', 'findBy', 'getBy'] as $methodName) {
                $retriever = [$repository, $methodName];
                if (method_exists(...$retriever)) {
                    return fn($value) => $retriever($identifier, $value);
                }
            }

            throw new \Exception();
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('no retriever found for model ' . $type . ($identifier ? (' identified by $' . $identifierName) : ''));
        }
    }

    private function getService(string $className): object {
        $objectOrRetriever = $this->services[$className] ?? null;
        if ($objectOrRetriever instanceof \Closure) {
            return $objectOrRetriever();
        } elseif ($objectOrRetriever) {
            return $objectOrRetriever;
        }

        // default retriever: $className::getInstance
        if (method_exists($className, 'getInstance')) {
            $method = new \ReflectionMethod($className, 'getInstance');
            if ($method->isStatic()) {
                return $method->invoke(null);
            }
        }

        // default retriever: create new object
        $constructor = new \ReflectionMethod($className, '__construct');
        $constructorArgs = $this->injectDependencies($constructor, []);
        return new $className(...$constructorArgs);
    }

    private function injectDependencies(\ReflectionMethod|\ReflectionFunction $method, array $matches = [], int $skipParameters = 0): array {
        $args = [];
        foreach ($method->getParameters() as $i=>$parameter) {
            if ($i < $skipParameters) {
                continue;
            }

            $type = $parameter->getType();

            // inject parameters from request body
            foreach ($parameter->getAttributes(RequestValue::class) as $attribute) {
                $requestValue = $attribute->newInstance();
                $key = $requestValue->name ?: $parameter->getName();
                $identifier = $requestValue->identifier ?: null;

                $payload = $this->request->getPayload();
                if (!$payload->has($key)) {
                    if ($parameter->isOptional()) {
                        $arg = $parameter->getDefaultValue();
                    } else {
                        throw new InvalidUserDataException($key . ' is missing in request body');
                    }
                } else {
                    $retriever = $this->getModelRetriever($type->getName(), $identifier);
                    $arg = $retriever($payload->get($key));
                }
                $args[$parameter->getName()] = $arg;
                continue 2;
            }

            // inject parameters from path and query string
            if (isset($matches[$parameter->getName()])) {
                [$identifierName, $match] = $matches[$parameter->getName()];
                $retriever = $this->getModelRetriever((string)$type, $identifierName);
                $arg = $retriever($match);
                if ($arg === null || $arg === []) {
                    throw new NotFoundException();
                }
                $args[$parameter->getName()] = $arg;
                continue;
            // inject services (or throw InvalidArgumentException)
            } else {
                $args[$parameter->getName()] = $this->getService((string)$type);
            }
        }
        return $args;
    }

    private function callConditionally(object|string $controller, string $functionName, array $matches = [], array|bool $conditions = true, array $arguments = [], bool $checkCsrfToken = false): Response
    {
        if (!is_object($controller)) {
            $constructor = new \ReflectionMethod($controller, '__construct');
            $constructorArgs = $this->injectDependencies($constructor, $matches);
            $this->controller = new $controller(...$constructorArgs);
        } else {
            $this->controller = $controller;
        }
        if ($checkCsrfToken) {
            $this->checkCsrfToken($this->request->getPayload()->get('_csrf_token'));
        }
        $method = new \ReflectionMethod($this->controller, $functionName);
        $args = [...$arguments, ...$this->injectDependencies($method, $matches, skipParameters: count($arguments))];
        if (is_array($conditions)) {
            if (!$this->currentUser) {
                throw new \LogicException('router has conditions but currentUser is NULL');
            }
            (new ConditionChecker($this->currentUser, $conditions))->check($args);
        } elseif ($conditions === false) {
            throw new AccessDeniedException('inactive route');
        }
        $response = $method->invokeArgs($this->controller, $args);
        $this->addCsrfTokenHeader($response);
        return $response;
    }
}
