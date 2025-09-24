<?php
/**
 * @author Henrik Gebauer <code@henrik-gebauer.de>
 * @license https://opensource.org/license/mit MIT
 */

declare(strict_types=1);

namespace Hengeb\Router;

use Hengeb\Router\Enum\ResponseType;
use Hengeb\Router\Exception\AccessDeniedException;
use Hengeb\Router\Exception\InvalidCsrfTokenException;
use Hengeb\Router\Exception\InvalidRouteException;
use Hengeb\Router\Exception\InvalidUserDataException;
use Hengeb\Router\Exception\NotFoundException;
use Hengeb\Router\Exception\NotLoggedInException;
use Hengeb\Router\Interface\CurrentUserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Router
 */
class Router {
    private RouteMap $routeMap;
    private DependencyInjector $dependencyInjector;

    private ?Request $request = null;
    private ?CurrentUserInterface $currentUser = null;

    /**
     * @var Array<callable|string[]>
     */
    private array $exceptionHandlers = [];
    private ?\Exception $exception = null;

    private ?ResponseType $responseType = null;

    public function __construct(string $controllerDir)
    {
        $this->routeMap = new RouteMap($controllerDir);
        $this->dependencyInjector = new DependencyInjector();

        $this
            ->addService(self::class, fn() => $this)
            ->addService(Request::class, fn() => $this->request)
            ->addService(ParameterBag::class, fn() => $this->request->getPayload())
            ->addService(\Exception::class, fn() => $this->exception)
            ->addService(CurrentUserInterface::class, fn() => $this->currentUser)
            ->addService(Session::class, function () {
                if (!$this->request) {
                    throw new \LogicException('cannot start session before calling Router::dispatch');
                }
                if (!$this->request->hasSession()) {
                    $this->request->setSession(new Session());
                }
                return $this->request->getSession();
            })
            ->addService(ResponseType::class, fn() => $this->responseType);
    }

    public function addType(string $type, callable $retriever, ?string $identifierName = null): self
    {
        $this->dependencyInjector->addType($type, $retriever, $identifierName);
        return $this;
    }

    public function addService(string $class, object $objectOrRetriever): self
    {
        $this->dependencyInjector->addService($class, $objectOrRetriever);
        return $this;
    }

    public function addExceptionHandler(string $exceptionClass, callable $handler): self
    {
        $this->exceptionHandlers[$exceptionClass] = $handler;
        return $this;
    }

    public function dispatch(Request $request, ?CurrentUserInterface $currentUser = null): Response {
        $this->request = $request;
        $this->currentUser = $currentUser;

        if ($currentUser) {
            $this->addService($currentUser::class, $this->currentUser);
        }

        try {
            foreach ($this->routeMap->getRoutes() as $matcher => [$httpMethod, $pathPattern, $queryInfo, $controllerName, $functionName, $accessConditions, $checkCsrfToken]) {
                if ($this->request->getMethod() !== $httpMethod || !preg_match($pathPattern, $this->request->getPathInfo(), $matches)) {
                    continue;
                }
                foreach ($queryInfo as $parameterName => $parameterPattern) {
                    if (!$this->request->query->has($parameterName) || !preg_match($parameterPattern, $this->request->query->getString($parameterName), $parameterMatches)) {
                        continue 2;
                    }
                    $matches += $parameterMatches;
                }

                if ($checkCsrfToken) {
                    $this->checkCsrfToken($this->request->getPayload()->get('_csrfToken'));
                }

                $controller = $this->dependencyInjector->createObject($controllerName);
                $conditionChecker = new ConditionChecker($accessConditions);

                $response = $this->call($controller, $functionName, $matches, $conditionChecker);
                $this->addCsrfTokenHeader($response);
                return $response;
            }
            throw new InvalidRouteException();
        } catch (\Exception $e) {
            return $this->handleException($controller ?? null, $e);
        }
    }

    /**
     * create a CSRF token and keep track of the recent 50 tokens in the session
     */
    public function createCsrfToken(): string
    {
        $session = $this->dependencyInjector->getService(Session::class);
        $token = bin2hex(random_bytes(32));
        $csrfTokens = $session->get('csrfTokens', []);
        $csrfTokens[$token] = time();
        // limit to a maximum of 50 tokens in the storage
        $csrfTokens = array_slice($csrfTokens, -50);
        $session->set('csrfTokens', $csrfTokens);
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
        $token = $this->request->getPayload()->get('_csrfToken');
        if (!$token) {
            $token = $this->request->headers->get('X-CSRF-Token');
        }
        $session = $this->dependencyInjector->getService(Session::class);
        $csrfTokens = $session->get('csrfTokens', []);
        // invalidate tokens after 1 hour
        $csrfTokens = array_filter($csrfTokens, fn($time) => $time + 3600 > time());
        if (!isset($csrfTokens[$token])) {
            throw new InvalidCsrfTokenException();
        }
        unset($csrfTokens[$token]);
        $session->set('csrfTokens', $csrfTokens);
    }

    private function handleException(?object $controller, \Exception $e): Response
    {
        $this->exception = $e;

        // stop possible output buffering (e.g. if the exception was thrown during the rendering of a template)
        ob_end_clean();
        if ($controller && method_exists($controller, 'handleException')) {
            return $this->call($controller, 'handleException');
        }

        foreach ($this->exceptionHandlers as $exceptionClass => $handler) {
            if (is_a($e::class, $exceptionClass, true)) {
                if (is_array($handler)) {
                    [$className, $methodName] = $handler;
                    $controller = $this->dependencyInjector->createObject($className);
                    return $this->call($controller, $methodName);
                // closure
                } else {
                    $function = new \ReflectionFunction($handler);
                    $args = $this->dependencyInjector->getFunctionArguments($function);
                    return $function->invokeArgs($args);
                }
            }
        }
        return $this->defaultExceptionHandler($e);
    }

    private function defaultExceptionHandler(\Exception $e): Response
    {
        $header = ['Content-Type' => 'text/plain; charset=utf-8'];

        [$message, $responseCode] = match (true) {
            $e instanceof InvalidRouteException => ['path not found', 404],
            $e instanceof NotLoggedInException => ['login required', 401],
            $e instanceof NotFoundException => ['resource not found', 404],
            $e instanceof AccessDeniedException => ['permission required', 403],
            $e instanceof InvalidCsrfTokenException => ['request cannot be repeated', 400],
            $e instanceof InvalidUserDataException => ['submitted data is missing or invalid', 400],
            default => (function () use ($e): array {
                error_log($e->getMessage());
                error_log($e->getTraceAsString());
                return ['internal error', 500];
            })(),
        };

        if ($e->getMessage()) {
            $message = $e->getMessage();
            if ($e->getCode()) {
                $message .= ' (code: ' . $e->getCode() . ')';
            }
        }

        return new Response($message, $responseCode, $header);
    }

    public function call(
        object|string $controller,
        string $functionName,
        array $matches = [],
        ?ConditionChecker $conditionChecker = null,
    ): Response {
        if (!is_object($controller)) {
            $controller = $this->dependencyInjector->createObject($controller);
        }
        $method = new \ReflectionMethod($controller, $functionName);
        if (!$this->responseType) {
            $this->responseType = match ((string) $method->getReturnType()) {
                JsonResponse::class, 'array' => ResponseType::Json,
                default => ResponseType::Html,
            };
        }

        $args = $this->dependencyInjector->getFunctionArguments($method, $matches);
        if ($conditionChecker) {
            if (!$this->currentUser) {
                throw new \LogicException('route has conditions but currentUser is NULL');
            }
            $conditionChecker->assertOrThrow($this->currentUser, $args);
        }
        $response = $method->invokeArgs($controller, $args);

        if (is_array($response)) {
            $response = new JsonResponse($response);
        } elseif (is_string($response)) {
            $response = new Response($response);
        }

        return $response;
    }
}
