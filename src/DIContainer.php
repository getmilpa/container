<?php

/**
 * This file is part of Milpa Container — the dependency injection container
 * of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/container
 */

declare(strict_types=1);

namespace Milpa\Container;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Milpa\Exceptions\CircularDependencyException;
use Milpa\Exceptions\ContainerResolutionException;
use Milpa\Exceptions\ServiceNotFoundException;
use Milpa\Interfaces\Di\DIContainerInterface;

/**
 * Reflection-autowiring dependency injection container.
 *
 * This is the maximal implementation of {@see DIContainerInterface}: the
 * interface only guarantees resolution of identifiers explicitly registered
 * via {@see registerService()} and documents auto-resolution of {@see get()}
 * and {@see has()} as a MAY, not a MUST. This class exercises that MAY —
 * it additionally auto-wires and caches unregistered-but-existing classes.
 * Concretely, beyond the interface's baseline, this implementation
 * guarantees:
 *
 * - {@see get()} and {@see has()} auto-resolve any existing, non-abstract,
 *   non-interface class whose constructor dependencies are themselves
 *   resolvable (recursively) — not just identifiers registered via
 *   {@see registerService()}.
 * - Auto-resolved classes are cached as singletons on first resolution:
 *   subsequent {@see get()} calls for the same identifier return the same
 *   instance, unless {@see resolve()} was called directly with
 *   `$singleton = false`.
 * - {@see resolve()} detects circular dependency chains (A needs B needs A,
 *   directly or transitively) and throws {@see CircularDependencyException}
 *   with the full chain of class names that produced the cycle.
 * - {@see tryGet()} never throws: it returns `null` for anything that is
 *   not registered and cannot be auto-resolved, instead of propagating
 *   {@see ServiceNotFoundException} or {@see ContainerResolutionException}.
 * - Classes found to be non-resolvable (interfaces, abstract classes,
 *   classes with unresolvable constructor parameters) are cached as such,
 *   so repeated lookups do not re-run reflection.
 *
 * @example
 * // Traditional usage
 * $container->registerService(MyService::class, new MyService($dep));
 * $service = $container->get(MyService::class);
 *
 * // Auto-wiring (no registration needed)
 * $service = $container->get(MyService::class); // Auto-resolved!
 *
 * // Safe retrieval (returns null if not resolvable)
 * $service = $container->tryGet(MyService::class);
 */
class DIContainer implements DIContainerInterface
{
    private ContainerBuilder $container;

    /**
     * Classes that should not be auto-resolved (interfaces, abstracts).
     *
     * @var array<string, bool>
     */
    private array $nonResolvable = [];

    /**
     * Class names currently being resolved on the current call stack, used to
     * detect circular dependencies during auto-wiring.
     *
     * @var array<string, bool>
     */
    private array $resolving = [];

    public function __construct()
    {
        $this->container = new ContainerBuilder();
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Registers a service under the given identifier.
     *
     * A class-string is registered on the underlying `ContainerBuilder` as a
     * public service definition (resolved and instantiated on first `get()`);
     * an object is set directly, bypassing definition-based resolution
     * entirely. Either way, this takes precedence over on-demand auto-wiring
     * via {@see resolve()} for the same identifier.
     *
     * @param string        $id              Service identifier (usually FQCN)
     * @param string|object $classOrInstance A class name to register for lazy resolution, or an already-built instance
     */
    public function registerService(string $id, string|object $classOrInstance): void
    {
        if (is_string($classOrInstance)) {
            $this->container->register($id, $classOrInstance)->setPublic(true);
        } else {
            $this->container->set($id, $classOrInstance);
        }
    }

    /**
     * Compiles the underlying container, freezing its service definitions.
     *
     * Delegates to Symfony `ContainerBuilder::compile()`: runs the builder's
     * compiler passes and locks the definitions in place. Auto-wired services
     * resolved before this call remain cached as singletons; explicit
     * {@see registerService()} calls made after compiling still work (this
     * container does not otherwise restrict post-compile mutation), but
     * compiling is meant to mark the point past which the service graph is
     * considered final.
     */
    public function compileContainer(): void
    {
        $this->container->compile();
    }

    /**
     * Get a service from the container.
     *
     * If the service is not registered but the class exists,
     * it will be auto-resolved and registered as a singleton.
     *
     * @param string $id Service identifier (usually FQCN)
     *
     * @return mixed Service instance
     *
     * @throws ServiceNotFoundException     If no entry exists for $id and it cannot be auto-resolved.
     * @throws ContainerResolutionException If auto-resolution is attempted but fails.
     */
    public function get(string $id): mixed
    {
        // Already registered - return directly
        if ($this->container->has($id)) {
            return $this->container->get($id);
        }

        // Try auto-wiring
        if (class_exists($id) && !isset($this->nonResolvable[$id])) {
            return $this->resolve($id, true);
        }

        throw ServiceNotFoundException::forId($id);
    }

    /**
     * Checks whether an identifier is resolvable.
     *
     * True for anything registered via {@see registerService()}. For an
     * unregistered identifier, this implementation additionally returns true
     * when it names an existing, non-abstract, non-interface class whose
     * constructor dependencies are (recursively) resolvable — the auto-wiring
     * guarantee documented on the class. The check never instantiates
     * anything; it walks constructor parameter types to decide resolvability.
     *
     * @param string $id Service identifier
     *
     * @return bool True if registered or auto-resolvable
     */
    public function has(string $id): bool
    {
        // Check if registered OR if it's a resolvable class
        if ($this->container->has($id)) {
            return true;
        }

        // Check if it's a resolvable class
        if (class_exists($id) && !isset($this->nonResolvable[$id])) {
            return $this->canResolve($id);
        }

        return false;
    }

    /**
     * Get a service or return null if not available.
     *
     * @param string $id Service identifier
     *
     * @return mixed|null Service instance or null
     */
    public function tryGet(string $id): mixed
    {
        try {
            if ($this->has($id)) {
                return $this->get($id);
            }
        } catch (\Exception) {
            // Silently fail
        }

        return null;
    }

    /**
     * Resolve a class with auto-wiring.
     *
     * @param string $className Fully qualified class name
     * @param bool   $singleton Register as singleton (default: true)
     *
     * @return mixed Class instance
     *
     * @throws CircularDependencyException  If resolving $className re-enters itself via its own dependency chain.
     * @throws ContainerResolutionException If the class does not exist, is abstract/an interface, or a
     *                                      constructor dependency cannot be resolved.
     */
    public function resolve(string $className, bool $singleton = true): mixed
    {
        if (isset($this->resolving[$className])) {
            $chain = array_keys($this->resolving);
            $chain[] = $className;

            throw CircularDependencyException::forChain($chain);
        }

        // Check for interfaces first (interface_exists is separate from class_exists)
        if (interface_exists($className)) {
            $this->nonResolvable[$className] = true;
            throw new ContainerResolutionException("Cannot auto-resolve abstract class or interface: {$className}");
        }

        if (!class_exists($className)) {
            throw new ContainerResolutionException("Class {$className} does not exist");
        }

        $reflection = new ReflectionClass($className);

        // Cannot instantiate abstract classes
        if ($reflection->isAbstract()) {
            $this->nonResolvable[$className] = true;
            throw new ContainerResolutionException("Cannot auto-resolve abstract class or interface: {$className}");
        }

        $this->resolving[$className] = true;

        try {
            $instance = $this->instantiate($reflection);
        } finally {
            unset($this->resolving[$className]);
        }

        // Register as singleton for future use
        if ($singleton) {
            $this->container->set($className, $instance);
        }

        return $instance;
    }

    /**
     * Check if a class can be auto-resolved.
     *
     * @param string $className Class name to check
     *
     * @return bool True if resolvable
     */
    private function canResolve(string $className): bool
    {
        // Interfaces cannot be resolved
        if (interface_exists($className)) {
            $this->nonResolvable[$className] = true;
            return false;
        }

        if (!class_exists($className)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($className);

            if ($reflection->isAbstract()) {
                $this->nonResolvable[$className] = true;
                return false;
            }

            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return true;
            }

            // Check if all constructor params can be resolved
            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();

                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();

                    // Can we resolve this dependency?
                    if (!$this->container->has($typeName) && !$this->canResolveType($typeName)) {
                        if (!$param->isDefaultValueAvailable() && !$param->allowsNull()) {
                            return false;
                        }
                    }
                } elseif (!$param->isDefaultValueAvailable() && !$param->allowsNull()) {
                    // Non-class type without default
                    return false;
                }
            }

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Check if a type can be resolved (without actually resolving).
     */
    private function canResolveType(string $typeName): bool
    {
        if (isset($this->nonResolvable[$typeName])) {
            return false;
        }

        // Interfaces cannot be resolved
        if (interface_exists($typeName)) {
            $this->nonResolvable[$typeName] = true;
            return false;
        }

        if (!class_exists($typeName)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($typeName);
            return !$reflection->isAbstract();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Instantiate a class resolving constructor dependencies.
     *
     * @param ReflectionClass<object> $reflection Class reflection
     *
     * @return object Class instance
     *
     * @throws ContainerResolutionException If a constructor dependency cannot be resolved
     */
    private function instantiate(ReflectionClass $reflection): object
    {
        $constructor = $reflection->getConstructor();

        // No constructor - simple instantiation
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $params = $constructor->getParameters();
        $args = [];

        foreach ($params as $param) {
            $type = $param->getType();

            // Class/interface type hint
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();

                // Try to get from container (will auto-resolve if needed)
                if ($this->container->has($typeName)) {
                    $args[] = $this->container->get($typeName);
                    continue;
                }

                // Try to auto-resolve the dependency
                if (class_exists($typeName) && !isset($this->nonResolvable[$typeName])) {
                    try {
                        $args[] = $this->resolve($typeName, true);
                        continue;
                    } catch (CircularDependencyException $e) {
                        // A real cycle, not a mere "can't auto-resolve" — surface it rather
                        // than silently falling through to default/null handling.
                        throw $e;
                    } catch (\Exception) {
                        // Fall through to default/null handling
                    }
                }
            }

            // Try default value
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            // Allow null
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            // Cannot resolve this parameter
            throw new ContainerResolutionException(
                "Cannot resolve parameter \${$param->getName()} " .
                "for class {$reflection->getName()}"
            );
        }

        return $reflection->newInstanceArgs($args);
    }
}
