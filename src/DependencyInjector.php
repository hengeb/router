<?php
/**
 * @author Henrik Gebauer <code@henrik-gebauer.de>
 * @license https://opensource.org/license/mit MIT
 */

declare(strict_types=1);

namespace Hengeb\Router;

use Hengeb\Router\Attribute\Inject;
use Hengeb\Router\Attribute\RequestValue;
use Hengeb\Router\Exception\InvalidUserDataException;
use Hengeb\Router\Interface\RetrievableModel;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Dependency Injector
 */
class DependencyInjector {
    /**
     * known types for dependency injection
     * [[string $type, callable $retriever, ?string $identifier], ...]
     */
    private array $types = [];

    /**
     * known services for dependency injection
     * @var Array<string, object|callable> $services [$className => callable $retriever, ...]
     */
    private array $services = [];

    public function __construct()
    {
        $this->addType('string', fn($v) => "$v");
        $this->addType('mixed', fn($v) => $v);
        $this->addType('', fn($v) => $v);
        $this->addType('int', 'intval');
        $this->addType('bool', 'boolval');
        $this->addType('float', 'floatval');
        $this->addType(\DateTimeImmutable::class, fn($v) => new \DateTimeImmutable($v));
        $this->addType(\DateTime::class, fn($v) => new \DateTime($v));
        $this->addType(\DateTimeInterface::class, fn($v) => new \DateTimeImmutable($v));
    }

    public function addType(string $type, callable $retriever, ?string $identifierName = null): void
    {
        $this->types[] = [$type, $retriever, $identifierName];
    }

    public function getModelRetriever(string $type, ?string $identifierName): callable {
        $best = null;

        // first look for a registered retriever for this type
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

        if (is_subclass_of($type, \BackedEnum::class)) {
            return fn($value) => $type::from($value);
        }

        if (is_subclass_of($type, RetrievableModel::class)) {
            return fn($value) => $type::retrieveModel($value, $identifierName);
        }

        throw new \InvalidArgumentException('no retriever found for model ' . $type . ($identifier ? (' identified by $' . $identifierName) : ''));
    }

    public function addService(string $className, object $objectOrRetriever): void
    {
        $this->services[$className] = $objectOrRetriever;
    }

    public function getService(string $className): object {
        $objectOrRetriever = $this->services[$className]
            ?? throw new \LogicException("unknown service: `$className`. Use \$router->addService($className::class, fn() => ...).");

        return $objectOrRetriever instanceof \Closure ? $objectOrRetriever() : $objectOrRetriever;
    }

    public function createObject(string $className): object
    {
        $constructorArgs = [];
        try {
            $constructor = new \ReflectionMethod($className, '__construct');
            $constructorArgs = $this->getFunctionArguments($constructor);
        } catch (\ReflectionException $e) {}

        $object = new $className(...$constructorArgs);

        // inject services in properties with the #[Inject] attribute
        $properties = (new \ReflectionClass($className))->getProperties(\ReflectionProperty::IS_PUBLIC);
        foreach ($properties as $property) {
            if (!count($property->getAttributes(Inject::class))) {
                continue;
            }
            $object->{$property->name} = $this->getService((string) $property->getType());
        }

        return $object;
    }

    /**
     * @param array<string $propertyName, mixed $value> $matches the matches in the #[Route] matcher with their value
     */
    public function getFunctionArguments(
        \ReflectionMethod|\ReflectionFunction $method,
        array $matches = [],
    ): array {
        $args = [];
        foreach ($method->getParameters() as $i=>$parameter) {
            $parameterName = $parameter->getName();
            $type = $parameter->getType();

            // inject parameters from request body
            foreach ($parameter->getAttributes(RequestValue::class) as $attribute) {
                error_log(var_export($parameterName, true));
                $requestValue = $attribute->newInstance();
                $key = $requestValue->name ?: $parameterName;
                $identifier = $requestValue->identifier ?: null;

                $payload ??= $this->getService(ParameterBag::class);
                if (!$payload->has($key)) {
                    if ($parameter->isOptional()) {
                        $arg = $parameter->getDefaultValue();
                    } else {
                        throw new InvalidUserDataException($key . ' is missing in request body');
                    }
                } else {
                    $retriever = $this->getModelRetriever((string) $type?->getName(), $identifier);
                    $arg = $retriever($payload->get($key));
                }
                $args[$parameterName] = $arg;
                continue 2;
            }

            // inject parameters from path and query string
            foreach ($matches as $key => $value) {
                // remove numerical regex match keys, only use named matches
                if (!is_string($key)) {
                    continue;
                }
                [$name, $identifierName] = str_contains($key, '__identifiedBy__')
                    ? explode('__identifiedBy__', $key)
                    : [$key, null];
                if ($parameterName !== $name) {
                    continue;
                }
                $retriever = $this->getModelRetriever((string)$type, $identifierName);
                $arg = $retriever($value);
                if ($arg === null || $arg === []) {
                    throw new NotFoundException();
                }
                $args[$parameterName] = $arg;
                continue 2;
            }

            // inject services (or throw LogicException)
            $args[$parameterName] = $this->getService((string)$type);
        }
        return $args;
    }
}