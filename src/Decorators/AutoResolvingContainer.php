<?php
declare(strict_types=1);

namespace Naf\Decorators;

use Naf\Exceptions\ContainerException;
use Naf\Exceptions\ServiceNotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Decorator for Container that adds automatic dependency resolution via reflection
 *
 * This decorator wraps a base container and extends its functionality by:
 * - Building classes via autowiring (constructor injection)
 * - Automatically resolving concrete class dependencies
 * - Detecting circular dependencies
 * - Caching reflection data for performance
 */
class AutoResolvingContainer implements ContainerInterface
{
    private array $instances = [];
    private array $building = [];
    private array $reflectionCache = [];

    /**
     * @param ContainerInterface $container The base container to decorate
     */
    public function __construct(
        private readonly ContainerInterface $container
    ) {}

    /**
     * Retrieves a service from the container (always singleton)
     *
     * Tries to get from cache, then from base container, or builds via autowiring
     *
     * @template T
     * @param class-string<T>|string $id
     *
     * @return T|mixed
     * @throws ServiceNotFoundException
     * @throws ContainerException|ContainerExceptionInterface
     */
    public function get(string $id): mixed
    {
        // 1. Already instantiated in this decorator?
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        // 2. Registered in base container?
        if ($this->container->has($id)) {
            $service = $this->container->get($id);

            // Cache the resolved service
            $this->instances[$id] = $service;

            return $service;
        }

        // 3. Not registered → service not found
        throw new ServiceNotFoundException("Service '$id' not found.");
    }

    /**
     * Registers a service in the base container
     *
     * @param string $id Service identifier (typically class name or interface)
     * @param callable|object $factory Factory closure or direct object instance
     * @return void
     */
    public function set(string $id, callable|object $factory): void
    {
        $this->container->set($id, $factory);

        // Clear instance cache for this service
        unset($this->instances[$id]);
    }

    /**
     * Checks whether a service is registered or already built
     *
     * @param string $id Service identifier
     * @return bool
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || $this->container->has($id);
    }

    /**
     * Removes a service and its instance from the container
     *
     * @param string $id Service identifier
     * @return void
     */
    public function reset(string $id): void
    {
        unset($this->instances[$id]);
        $this->container->reset($id);
    }

    /**
     * Builds a class via autowiring (constructor injection)
     *
     * Can be called externally for ad-hoc instances (e.g., Commands, Controllers)
     * Automatically resolves dependencies from the container or builds concrete classes
     *
     * @template T
     * @param class-string<T> $className Fully qualified class name
     * @param array $parameters Optional constructor parameters (by name or position)
     * @param bool $singleton If true, the instance will be stored in container for re-usage
     * @return T
     * @throws ContainerException
     * @throws ServiceNotFoundException
     */
    public function make(string $className, array $parameters = [], bool $singleton = false)
    {
        // If singleton requested and already exists
        if ($singleton && isset($this->instances[$className])) {
            return $this->instances[$className];
        }

        // Circular dependency detection
        if (isset($this->building[$className])) {
            throw new ContainerException(
                "Circular dependency detected: " . implode(' -> ', array_keys($this->building)) . " -> $className"
            );
        }

        $this->building[$className] = true;

        try {
            $reflection = $this->reflect($className);

            if (!$reflection->isInstantiable()) {
                throw new ContainerException("Class '$className' is not instantiable.");
            }

            $constructor = $reflection->getConstructor();

            // No dependencies
            if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
                $instance = $reflection->newInstance();
            } else {
                $args = $this->resolveParameters($constructor->getParameters(), $className, $parameters);
                $instance = $reflection->newInstanceArgs($args);
            }

            // Optionally store as singleton
            if ($singleton) {
                $this->instances[$className] = $instance;
                $this->container->set($className, $instance);
            }

            return $instance;
        } finally {
            unset($this->building[$className]);
        }
    }

    /**
     * Returns cached reflection class or creates new one
     *
     * @param string $className
     * @return ReflectionClass
     * @throws ContainerException
     */
    private function reflect(string $className): ReflectionClass
    {
        if (!isset($this->reflectionCache[$className])) {
            try {
                $this->reflectionCache[$className] = new ReflectionClass($className);
            } catch (ReflectionException $e) {
                throw new ContainerException("Class '$className' cannot be reflected.", 0, $e);
            }
        }

        return $this->reflectionCache[$className];
    }

    /**
     * Resolves constructor parameters for dependency injection
     *
     * @param ReflectionParameter[] $parameters ReflectionParameter array
     * @param string $context Class name for error messages
     * @param array $explicitParams User-provided parameters (by name or position)
     *
     * @return array Resolved parameter values
     * @throws ContainerException
     * @throws ServiceNotFoundException|ContainerExceptionInterface
     */
    private function resolveParameters(array $parameters, string $context, array $explicitParams = []): array
    {
        $args = [];

        foreach ($parameters as $index => $param) {
            $paramName = $param->getName();

            // PRIORITY 1: Explicitly provided by parameter name
            if (array_key_exists($paramName, $explicitParams)) {
                $args[] = $explicitParams[$paramName];
                continue;
            }

            // PRIORITY 2: Explicitly provided by position
            if (array_key_exists($index, $explicitParams)) {
                $args[] = $explicitParams[$index];
                continue;
            }

            // PRIORITY 3: Try autowiring
            $type = $param->getType();

            // Scalar/builtin type without explicit value
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                $args[] = $this->resolveScalarParameter($param, $context, $explicitParams);
                continue;
            }

            // Class type → retrieve from container or auto-resolve
            $dependencyId = $type->getName();

            try {
                $args[] = $this->get($dependencyId);
            } catch (ServiceNotFoundException $e) {
                // If nullable, use null
                if ($type->allowsNull()) {
                    $args[] = null;
                    continue;
                }

                // Try auto-resolving if it's a concrete class
                if ($this->canAutoResolve($dependencyId)) {
                    $args[] = $this->make($dependencyId);
                    continue;
                }

                throw new ServiceNotFoundException(
                    "Cannot resolve dependency '$dependencyId' for parameter '\${$param->getName()}' in '$context'. " .
                    "Make sure the service is registered in the container or that it's a concrete class.",
                    0,
                    $e
                );
            }
        }

        return $args;
    }

    /**
     * Checks if a class can be auto-resolved (is a concrete, instantiable class)
     *
     * @param string $className
     * @return bool
     */
    private function canAutoResolve(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        try {
            $reflection = $this->reflect($className);
            return $reflection->isInstantiable();
        } catch (ContainerException) {
            return false;
        }
    }

    /**
     * Handles scalar/built-in type parameters
     *
     * @param ReflectionParameter $param
     * @param string              $context Class name for error messages
     * @param array               $explicitParams
     *
     * @return mixed Parameter value (default value or null)
     */
    private function resolveScalarParameter(ReflectionParameter $param, string $context, array $explicitParams = []): mixed
    {
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->allowsNull()) {
            return null;
        }

        if (!empty($explicitParams)) {
            $type = $param->getType();

            if ($type instanceof ReflectionNamedType) {
                $typeName = $type->getName();

                if ($typeName === 'array') {
                    return $explicitParams;
                }

                if (count($explicitParams) === 1) {
                    return reset($explicitParams);
                }
            }

            if (!$type) {
                return count($explicitParams) === 1 ? reset($explicitParams) : $explicitParams;
            }
        }

        throw new ContainerException(
            sprintf(
                "Cannot autowire parameter '\$%s' in '%s' (no class type and no default value).",
                $param->getName(),
                $context
            )
        );
    }
}