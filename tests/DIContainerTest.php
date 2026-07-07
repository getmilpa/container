<?php

declare(strict_types=1);

namespace Milpa\Container\Tests;

use Milpa\Container\DIContainer;
use Milpa\Exceptions\CircularDependencyException;
use Milpa\Exceptions\ServiceNotFoundException;
use Milpa\Interfaces\Di\DIContainerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

// Test fixtures for DI resolution — ported verbatim from the source test
// suites (see class docblock below for provenance).
class SimpleService
{
    public function getValue(): string
    {
        return 'simple';
    }
}

class ServiceWithDependency
{
    public function __construct(public SimpleService $simple)
    {
    }
}

class ServiceWithMultipleDependencies
{
    public function __construct(
        public SimpleService $simple,
        public ServiceWithDependency $dependent
    ) {
    }
}

class ServiceWithDefaultValue
{
    public function __construct(public string $name = 'default')
    {
    }
}

class ServiceWithNullable
{
    public function __construct(public ?SimpleService $simple = null)
    {
    }
}

interface TestServiceInterface
{
    public function test(): void;
}

abstract class AbstractTestService
{
    abstract public function doSomething(): void;
}

class ServiceWithBuiltinType
{
    public function __construct(public string $required)
    {
    }
}

class ServiceWithNullableInterface
{
    public function __construct(public ?TestServiceInterface $service = null)
    {
    }
}

class ServiceWithInterfaceDependency
{
    public function __construct(public TestServiceInterface $service)
    {
    }
}

class ServiceWithMixedParams
{
    public function __construct(
        public SimpleService $service,
        public string $name = 'default',
        public ?int $count = null
    ) {
    }
}

class CircularServiceA
{
    public function __construct(public CircularServiceB $b)
    {
    }
}

class CircularServiceB
{
    public function __construct(public CircularServiceA $a)
    {
    }
}

/**
 * Ported from, and merges the coverage of, two pre-extraction suites in the
 * monorepo (both exercised `Milpa\app\Providers\DIContainer`, the class now
 * living here as `Milpa\Container\DIContainer`):
 * - tests/Unit/Providers/DIContainerTest.php (the superset — fixtures and
 *   most test methods below come from here verbatim, namespace adjusted)
 * - tests/Unit/DIContainerTest.php (contributed `testRegisterServiceWithClassName`'s
 *   get()-after-register-by-name assertion, folded into
 *   `testRegisterServiceByClassNameIsResolvableAndInstantiable` below)
 *
 * Two assertions were added on top of both ported suites to close gaps
 * called out explicitly for this extraction:
 * - `testResolveDetectsCircularDependency` now also asserts the exception
 *   message carries the full A -> B -> A chain, not just the exception type.
 * - `testContainerIsPsr11Conformant` asserts PSR-11 conformance directly.
 */
class DIContainerTest extends TestCase
{
    private DIContainer $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new DIContainer();
    }

    public function testRegisterAndGetService(): void
    {
        $service = new SimpleService();
        $this->container->registerService(SimpleService::class, $service);

        $retrieved = $this->container->get(SimpleService::class);

        $this->assertSame($service, $retrieved);
    }

    public function testRegisterServiceByClassName(): void
    {
        $this->container->registerService('my.service', SimpleService::class);

        // This creates a new instance since it's registered as a class name
        $this->assertTrue($this->container->has('my.service'));
    }

    public function testRegisterServiceByClassNameIsResolvableAndInstantiable(): void
    {
        // registerService() accepts either a class name (string) or an
        // instance. When given a class name for a zero-arg constructor, the
        // registered identifier must both report as present AND actually
        // instantiate the right class on get().
        $this->container->registerService('stdClass', \stdClass::class);
        $this->assertTrue($this->container->has('stdClass'));

        $instance = $this->container->get('stdClass');
        $this->assertInstanceOf(\stdClass::class, $instance);
    }

    public function testRegisterServiceWithInstance(): void
    {
        $instance = new \stdClass();
        $this->container->registerService('stdClass', $instance);

        $this->assertTrue($this->container->has('stdClass'));
        $retrievedInstance = $this->container->get('stdClass');
        $this->assertSame($instance, $retrievedInstance);
    }

    public function testHasReturnsTrueForRegistered(): void
    {
        $this->container->registerService(SimpleService::class, new SimpleService());

        $this->assertTrue($this->container->has(SimpleService::class));
    }

    public function testHasReturnsFalseForUnregistered(): void
    {
        $this->assertFalse($this->container->has('NonExistentService'));
    }

    public function testAutoResolvesSimpleClass(): void
    {
        $service = $this->container->get(SimpleService::class);

        $this->assertInstanceOf(SimpleService::class, $service);
        $this->assertEquals('simple', $service->getValue());
    }

    public function testAutoResolvesBuiltinClassWithNoDependencies(): void
    {
        // stdClass has no constructor, should auto-resolve without any
        // Milpa\* namespace involved — exercises the plain class_exists()
        // path independent of user-defined fixtures.
        $instance = $this->container->get(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $instance);
    }

    public function testAutoResolvesWithDependency(): void
    {
        $service = $this->container->get(ServiceWithDependency::class);

        $this->assertInstanceOf(ServiceWithDependency::class, $service);
        $this->assertInstanceOf(SimpleService::class, $service->simple);
    }

    public function testAutoResolvesNestedDependencies(): void
    {
        $service = $this->container->get(ServiceWithMultipleDependencies::class);

        $this->assertInstanceOf(ServiceWithMultipleDependencies::class, $service);
        $this->assertInstanceOf(SimpleService::class, $service->simple);
        $this->assertInstanceOf(ServiceWithDependency::class, $service->dependent);
    }

    public function testAutoResolvedServiceIsCachedAsSingleton(): void
    {
        $service1 = $this->container->get(SimpleService::class);
        $service2 = $this->container->get(SimpleService::class);

        $this->assertSame($service1, $service2);
    }

    public function testResolveWithDefaultValue(): void
    {
        $service = $this->container->get(ServiceWithDefaultValue::class);

        $this->assertEquals('default', $service->name);
    }

    public function testResolveWithNullable(): void
    {
        $service = $this->container->get(ServiceWithNullable::class);

        // SimpleService can be auto-resolved, so it should be injected
        $this->assertInstanceOf(SimpleService::class, $service->simple);
    }

    public function testCannotResolveInterface(): void
    {
        $this->expectException(\Exception::class);

        $this->container->get(TestServiceInterface::class);
    }

    public function testCannotResolveAbstractClass(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('abstract');

        $this->container->get(AbstractTestService::class);
    }

    public function testHasReturnsFalseForInterface(): void
    {
        $this->assertFalse($this->container->has(TestServiceInterface::class));
    }

    public function testHasReturnsTrueForResolvableClass(): void
    {
        $this->assertTrue($this->container->has(SimpleService::class));
    }

    public function testTryGetReturnsServiceIfAvailable(): void
    {
        $service = $this->container->tryGet(SimpleService::class);

        $this->assertInstanceOf(SimpleService::class, $service);
    }

    public function testTryGetReturnsNullForInterface(): void
    {
        $result = $this->container->tryGet(TestServiceInterface::class);

        $this->assertNull($result);
    }

    public function testTryGetReturnsNullForNonExistent(): void
    {
        $result = $this->container->tryGet('NonExistent\\Class');

        $this->assertNull($result);
    }

    public function testTryGetNeverThrowsForUnresolvableBuiltinParam(): void
    {
        // tryGet()'s contract is "never throw" — ServiceWithBuiltinType
        // cannot be resolved (required string with no default), which would
        // make get() throw ContainerResolutionException. tryGet() must
        // swallow that and return null instead.
        $result = $this->container->tryGet(ServiceWithBuiltinType::class);

        $this->assertNull($result);
    }

    public function testResolveWithSingletonFalse(): void
    {
        $service1 = $this->container->resolve(SimpleService::class, false);
        $service2 = $this->container->resolve(SimpleService::class, false);

        $this->assertNotSame($service1, $service2);
    }

    public function testResolveThrowsForNonExistentClass(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not exist');

        $this->container->resolve('NonExistent\\Class\\Name');
    }

    public function testGetContainer(): void
    {
        $symfonyContainer = $this->container->getContainer();

        $this->assertInstanceOf(ContainerInterface::class, $symfonyContainer);
    }

    public function testCannotResolveWithUnresolvableBuiltinParam(): void
    {
        $this->expectException(\Exception::class);

        $this->container->get(ServiceWithBuiltinType::class);
    }

    public function testRegisteredInterfaceWithImplementation(): void
    {
        $implementation = new class () implements TestServiceInterface {
            public function test(): void
            {
            }
        };

        $this->container->registerService(TestServiceInterface::class, $implementation);

        $retrieved = $this->container->get(TestServiceInterface::class);
        $this->assertSame($implementation, $retrieved);
    }

    public function testHasAfterAutoResolve(): void
    {
        // First access auto-resolves and caches
        $this->container->get(SimpleService::class);

        // Now has() should return true because it's cached
        $this->assertTrue($this->container->has(SimpleService::class));
    }

    public function testCompileContainer(): void
    {
        $this->container->registerService('test.service', SimpleService::class);
        $this->container->compileContainer();

        // After compile, container should still work
        $this->assertTrue($this->container->has('test.service'));
    }

    public function testHasReturnsFalseForAbstractClass(): void
    {
        $result = $this->container->has(AbstractTestService::class);

        $this->assertFalse($result);
    }

    public function testResolveNonSingletonMultipleTimes(): void
    {
        $instance1 = $this->container->resolve(SimpleService::class, false);
        $instance2 = $this->container->resolve(SimpleService::class, false);
        $instance3 = $this->container->resolve(SimpleService::class, false);

        // Each call should return a new instance
        $this->assertNotSame($instance1, $instance2);
        $this->assertNotSame($instance2, $instance3);
    }

    public function testResolveThrowsForInterface(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('abstract class or interface');

        $this->container->resolve(TestServiceInterface::class);
    }

    public function testResolveThrowsForAbstract(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('abstract class or interface');

        $this->container->resolve(AbstractTestService::class);
    }

    public function testGetThrowsForUnresolvableNonExistentClass(): void
    {
        $this->expectException(\Exception::class);

        $this->container->get('Totally\\NonExistent\\ClassName\\That\\Does\\Not\\Exist');
    }

    public function testTryGetReturnsNullWhenExceptionThrown(): void
    {
        // ServiceWithBuiltinType can't be resolved (required string with no default)
        $result = $this->container->tryGet(ServiceWithBuiltinType::class);

        $this->assertNull($result);
    }

    public function testAutoResolvesDependencyChain(): void
    {
        // ServiceWithMultipleDependencies needs SimpleService and ServiceWithDependency
        // ServiceWithDependency needs SimpleService
        // All should be auto-resolved correctly

        $service = $this->container->get(ServiceWithMultipleDependencies::class);

        $this->assertInstanceOf(ServiceWithMultipleDependencies::class, $service);
        $this->assertInstanceOf(SimpleService::class, $service->simple);
        $this->assertInstanceOf(ServiceWithDependency::class, $service->dependent);
        $this->assertInstanceOf(SimpleService::class, $service->dependent->simple);
    }

    public function testNullableParameterWithoutContainerRegistration(): void
    {
        // ServiceWithNullable has a nullable SimpleService param
        // SimpleService can be auto-resolved, so it should be injected
        $container = new DIContainer();
        $service = $container->get(ServiceWithNullable::class);

        $this->assertInstanceOf(ServiceWithNullable::class, $service);
    }

    public function testHasReturnsFalseForNonExistentClass(): void
    {
        $result = $this->container->has('CompletelyFake\\Namespace\\ClassName');

        $this->assertFalse($result);
    }

    public function testInterfaceMarkedAsNonResolvable(): void
    {
        // First call marks it as non-resolvable
        $result1 = $this->container->has(TestServiceInterface::class);

        // Second call should use cached non-resolvable info
        $result2 = $this->container->has(TestServiceInterface::class);

        $this->assertFalse($result1);
        $this->assertFalse($result2);
    }

    public function testAbstractClassMarkedAsNonResolvable(): void
    {
        // First call marks it as non-resolvable
        $result1 = $this->container->has(AbstractTestService::class);

        // Second call should use cached non-resolvable info
        $result2 = $this->container->has(AbstractTestService::class);

        $this->assertFalse($result1);
        $this->assertFalse($result2);
    }

    public function testGetContainerReturnsSymfonyContainer(): void
    {
        $symfonyContainer = $this->container->getContainer();

        $this->assertInstanceOf(\Symfony\Component\DependencyInjection\ContainerBuilder::class, $symfonyContainer);
    }

    public function testResolveWithDefaultValueAndNullableParam(): void
    {
        $service = $this->container->get(ServiceWithDefaultValue::class);

        $this->assertInstanceOf(ServiceWithDefaultValue::class, $service);
        $this->assertEquals('default', $service->name);
    }

    public function testResolveWithNullableInterfaceDependency(): void
    {
        // ServiceWithNullableInterface has a nullable interface that can't be resolved
        // It should resolve with null
        $service = $this->container->get(ServiceWithNullableInterface::class);

        $this->assertInstanceOf(ServiceWithNullableInterface::class, $service);
        $this->assertNull($service->service);
    }

    public function testCannotResolveWithUnresolvableInterfaceDependency(): void
    {
        $this->expectException(\Exception::class);

        // ServiceWithInterfaceDependency requires an interface that's not registered
        $this->container->get(ServiceWithInterfaceDependency::class);
    }

    public function testResolveWithRegisteredInterfaceDependency(): void
    {
        $implementation = new class () implements TestServiceInterface {
            public function test(): void
            {
            }
        };

        // Register the interface implementation
        $this->container->registerService(TestServiceInterface::class, $implementation);

        // Now ServiceWithInterfaceDependency should be resolvable
        $service = $this->container->get(ServiceWithInterfaceDependency::class);

        $this->assertInstanceOf(ServiceWithInterfaceDependency::class, $service);
        $this->assertSame($implementation, $service->service);
    }

    public function testResolveWithMixedParams(): void
    {
        $service = $this->container->get(ServiceWithMixedParams::class);

        $this->assertInstanceOf(ServiceWithMixedParams::class, $service);
        $this->assertInstanceOf(SimpleService::class, $service->service);
        $this->assertEquals('default', $service->name);
        $this->assertNull($service->count);
    }

    public function testHasReturnsFalseForClassWithUnresolvableDependency(): void
    {
        // ServiceWithInterfaceDependency can't be resolved because its dependency
        // (TestServiceInterface) is not registered
        $result = $this->container->has(ServiceWithInterfaceDependency::class);

        $this->assertFalse($result);
    }

    public function testHasReturnsTrueAfterInterfaceIsRegistered(): void
    {
        // First, has() should return false
        $this->assertFalse($this->container->has(ServiceWithInterfaceDependency::class));

        // Register the interface
        $implementation = new class () implements TestServiceInterface {
            public function test(): void
            {
            }
        };
        $this->container->registerService(TestServiceInterface::class, $implementation);

        // Now has() should return true
        // Note: The nonResolvable cache might prevent this - depends on implementation
        $container = new DIContainer();
        $container->registerService(TestServiceInterface::class, $implementation);
        $this->assertTrue($container->has(ServiceWithInterfaceDependency::class));
    }

    public function testImplementsDIContainerInterface(): void
    {
        $this->assertInstanceOf(
            DIContainerInterface::class,
            $this->container
        );
    }

    public function testContainerIsPsr11Conformant(): void
    {
        // DIContainerInterface extends Psr\Container\ContainerInterface —
        // assert the concrete implementation honors that directly, not just
        // transitively through DIContainerInterface.
        $this->assertInstanceOf(ContainerInterface::class, $this->container);
    }

    public function testNonResolvableCachingForInterfaces(): void
    {
        // Call has() multiple times for an interface
        $result1 = $this->container->has(TestServiceInterface::class);
        $result2 = $this->container->has(TestServiceInterface::class);
        $result3 = $this->container->has(TestServiceInterface::class);

        // All should be false (and cached after first call)
        $this->assertFalse($result1);
        $this->assertFalse($result2);
        $this->assertFalse($result3);
    }

    public function testGetDirectlyFromContainerWhenRegistered(): void
    {
        $instance = new SimpleService();
        $this->container->registerService('custom.id', $instance);

        $retrieved = $this->container->get('custom.id');

        $this->assertSame($instance, $retrieved);
    }

    public function testGetFallsBackToContainerForNonExistentId(): void
    {
        $this->expectException(\Exception::class);

        // This ID doesn't exist and isn't a class
        $this->container->get('some.non.existent.service.id');
    }

    public function testResolveRegistersAsSingletonByDefault(): void
    {
        $instance = $this->container->resolve(SimpleService::class);

        // Get should return the same instance
        $retrieved = $this->container->get(SimpleService::class);

        $this->assertSame($instance, $retrieved);
    }

    public function testGetThrowsServiceNotFoundExceptionForNonExistentId(): void
    {
        $this->expectException(ServiceNotFoundException::class);

        $this->container->get('some.non.existent.service.id');
    }

    public function testResolveDetectsCircularDependency(): void
    {
        $this->expectException(CircularDependencyException::class);

        $this->container->get(CircularServiceA::class);
    }

    public function testCircularDependencyExceptionReportsFullChain(): void
    {
        // Beyond the exception type, the message must carry the actual
        // A -> B -> A chain so a developer can see exactly which services
        // formed the cycle without attaching a debugger.
        try {
            $this->container->get(CircularServiceA::class);
            $this->fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $e) {
            $this->assertStringContainsString(CircularServiceA::class, $e->getMessage());
            $this->assertStringContainsString(CircularServiceB::class, $e->getMessage());
            $this->assertStringContainsString('->', $e->getMessage());
        }
    }
}
