<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Core\Container;
use Naf\Decorators\AutoResolvingContainer;
use Naf\Exceptions\ContainerException;
use Naf\Exceptions\ServiceNotFoundException;
use Tests\NafTestCase;

// Test interfaces
interface LoggerInterface {}
interface DatabaseInterface {}

// Test implementations
class FileLogger implements LoggerInterface {
    public string $type = 'file';
}

class ConsoleLogger implements LoggerInterface {
    public string $type = 'console';
}

class MySQLDatabase implements DatabaseInterface {
    public string $driver = 'mysql';
}

// Simple concrete class without dependencies
class SimpleService {
    public string $name = 'simple';
}

// Service with interface dependency
class UserService {
    public function __construct(
        public LoggerInterface $logger
    ) {}
}

// Service with multiple dependencies
class OrderService {
    public function __construct(
        public LoggerInterface $logger,
        public DatabaseInterface $database
    ) {}
}

// Concrete class depending on another concrete class
class ConcreteServiceA {
    public string $id = 'A';
}

class ConcreteServiceB {
    public function __construct(
        public ConcreteServiceA $serviceA
    ) {}
}

// Mixed: interface + concrete class dependencies
class MixedDependencyService {
    public function __construct(
        public LoggerInterface $logger,
        public ConcreteServiceA $serviceA
    ) {}
}

// Service with scalar parameters
class ServiceWithScalars {
    public function __construct(
        public string $name,
        public int $count = 10
    ) {}
}

// Service with nullable dependency
class ServiceWithNullable {
    public function __construct(
        public ?LoggerInterface $logger = null
    ) {}
}

// Service with optional dependency
class ServiceWithOptional {
    public function __construct(
        public LoggerInterface $logger,
        public ?DatabaseInterface $database = null
    ) {}
}

// Abstract class (not instantiable)
abstract class AbstractService {
    abstract public function execute(): void;
}

// Circular dependency classes
class CircularA {
    public function __construct(public CircularB $b) {}
}

class CircularB {
    public function __construct(public CircularA $a) {}
}

// Deep dependency chain
class DeepServiceC {
    public function __construct(public string $value = 'deep') {}
}

class DeepServiceB {
    public function __construct(public DeepServiceC $serviceC) {}
}

class DeepServiceA {
    public function __construct(
        public DeepServiceB $serviceB,
        public LoggerInterface $logger
    ) {}
}

class AutoResolvingContainerTest extends NafTestCase {

    private Container $baseContainer;
    private AutoResolvingContainer $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseContainer = new Container();
        $this->container = new AutoResolvingContainer($this->baseContainer);
    }

    public function testCanRegisterAndRetrieveService(): void
    {
        $logger = new FileLogger();
        $this->container->set(LoggerInterface::class, $logger);

        $retrieved = $this->container->get(LoggerInterface::class);

        $this->assertSame($logger, $retrieved);
    }

    public function testCanRegisterServiceWithFactory(): void
    {
        $this->container->set(LoggerInterface::class, fn() => new FileLogger());

        $logger = $this->container->get(LoggerInterface::class);

        $this->assertInstanceOf(FileLogger::class, $logger);
    }

    public function testFactoryIsSingleton(): void
    {
        $this->container->set(LoggerInterface::class, fn() => new FileLogger());

        $logger1 = $this->container->get(LoggerInterface::class);
        $logger2 = $this->container->get(LoggerInterface::class);

        $this->assertSame($logger1, $logger2);
    }

    public function testHasReturnsTrueForRegisteredService(): void
    {
        $this->container->set(LoggerInterface::class, new FileLogger());

        $this->assertTrue($this->container->has(LoggerInterface::class));
    }

    public function testHasReturnsFalseForUnregisteredService(): void
    {
        $this->assertFalse($this->container->has(LoggerInterface::class));
    }

    public function testGetThrowsExceptionForUnregisteredService(): void
    {
        $this->expectException(ServiceNotFoundException::class);
        $this->expectExceptionMessage("Service 'NonExistent' not found");

        $this->container->get('NonExistent');
    }

    public function testCanResetService(): void
    {
        $this->container->set(LoggerInterface::class, new FileLogger());
        $this->container->reset(LoggerInterface::class);

        $this->assertFalse($this->container->has(LoggerInterface::class));
    }

    // === Autowiring Tests ===

    public function testMakeBuildsSimpleClass(): void
    {
        $service = $this->container->make(SimpleService::class);

        $this->assertInstanceOf(SimpleService::class, $service);
        $this->assertSame('simple', $service->name);
    }

    public function testMakeResolvesInterfaceDependency(): void
    {
        $this->container->set(LoggerInterface::class, new FileLogger());

        $service = $this->container->make(UserService::class);

        $this->assertInstanceOf(UserService::class, $service);
        $this->assertInstanceOf(FileLogger::class, $service->logger);
    }

    public function testMakeResolvesMultipleDependencies(): void
    {
        $this->container->set(LoggerInterface::class, new FileLogger());
        $this->container->set(DatabaseInterface::class, new MySQLDatabase());

        $service = $this->container->make(OrderService::class);

        $this->assertInstanceOf(OrderService::class, $service);
        $this->assertInstanceOf(LoggerInterface::class, $service->logger);
        $this->assertInstanceOf(DatabaseInterface::class, $service->database);
    }

    public function testMakeAutoResolvesConcreteDependency(): void
    {
        // ConcreteServiceA is NOT registered, should be auto-resolved
        $service = $this->container->make(ConcreteServiceB::class);

        $this->assertInstanceOf(ConcreteServiceB::class, $service);
        $this->assertInstanceOf(ConcreteServiceA::class, $service->serviceA);
        $this->assertSame('A', $service->serviceA->id);
    }

    public function testMakeHandlesMixedDependencies(): void
    {
        // Register interface, auto-resolve concrete class
        $this->container->set(LoggerInterface::class, new ConsoleLogger());

        $service = $this->container->make(MixedDependencyService::class);

        $this->assertInstanceOf(MixedDependencyService::class, $service);
        $this->assertInstanceOf(ConsoleLogger::class, $service->logger);
        $this->assertInstanceOf(ConcreteServiceA::class, $service->serviceA);
    }

    public function testMakeThrowsExceptionForUnregisteredInterfaceDependency(): void
    {
        $this->expectException(ServiceNotFoundException::class);
        $this->expectExceptionMessage("Cannot resolve dependency");

        // UserService needs LoggerInterface which is not registered
        $this->container->make(UserService::class);
    }

    public function testMakeThrowsExceptionForAbstractClass(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("is not instantiable");

        $this->container->make(AbstractService::class);
    }

    public function testMakeThrowsExceptionForScalarParameters(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot autowire parameter");

        // ServiceWithScalars requires string $name without default
        $this->container->make(ServiceWithScalars::class);
    }

    public function testMakeHandlesNullableDependencies(): void
    {
        // LoggerInterface is not registered, but it's nullable
        $service = $this->container->make(ServiceWithNullable::class);

        $this->assertInstanceOf(ServiceWithNullable::class, $service);
        $this->assertNull($service->logger);
    }

    public function testMakeHandlesOptionalDependencies(): void
    {
        $this->container->set(LoggerInterface::class, new FileLogger());
        // DatabaseInterface is not registered but has default null

        $service = $this->container->make(ServiceWithOptional::class);

        $this->assertInstanceOf(ServiceWithOptional::class, $service);
        $this->assertInstanceOf(FileLogger::class, $service->logger);
        $this->assertNull($service->database);
    }

    public function testMakeDetectsCircularDependencies(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Circular dependency detected");

        $this->container->make(CircularA::class);
    }

    public function testMakeWithSingletonFlag(): void
    {
        $service1 = $this->container->make(SimpleService::class, singleton: true);
        $service2 = $this->container->make(SimpleService::class, singleton: true);

        $this->assertSame($service1, $service2);
    }

    public function testMakeWithoutSingletonCreatesNewInstance(): void
    {
        $service1 = $this->container->make(SimpleService::class, singleton: false);
        $service2 = $this->container->make(SimpleService::class, singleton: false);

        $this->assertNotSame($service1, $service2);
        $this->assertEquals($service1, $service2);
    }

    public function testMakeResolvesDeepDependencyChain(): void
    {
        $this->container->set(LoggerInterface::class, new FileLogger());

        // DeepServiceA -> DeepServiceB -> DeepServiceC
        $service = $this->container->make(DeepServiceA::class);

        $this->assertInstanceOf(DeepServiceA::class, $service);
        $this->assertInstanceOf(DeepServiceB::class, $service->serviceB);
        $this->assertInstanceOf(DeepServiceC::class, $service->serviceB->serviceC);
        $this->assertSame('deep', $service->serviceB->serviceC->value);
    }

    public function testMakeCachesReflectionData(): void
    {
        $this->container->set(LoggerInterface::class, new FileLogger());

        // Build same class multiple times
        $service1 = $this->container->make(UserService::class);
        $service2 = $this->container->make(UserService::class);

        // Should work without errors (reflection cached)
        $this->assertInstanceOf(UserService::class, $service1);
        $this->assertInstanceOf(UserService::class, $service2);
        $this->assertNotSame($service1, $service2);
    }

    public function testSetClearsInstanceCache(): void
    {
        $logger1 = new FileLogger();
        $this->container->set(LoggerInterface::class, $logger1);

        $retrieved1 = $this->container->get(LoggerInterface::class);

        // Replace with new instance
        $logger2 = new ConsoleLogger();
        $this->container->set(LoggerInterface::class, $logger2);

        $retrieved2 = $this->container->get(LoggerInterface::class);

        $this->assertSame($logger1, $retrieved1);
        $this->assertSame($logger2, $retrieved2);
        $this->assertNotSame($retrieved1, $retrieved2);
    }

    public function testDecoratorDelegatesHasToBaseContainer(): void
    {
        $this->baseContainer->set('test', fn() => 'value');

        $this->assertTrue($this->container->has('test'));
        $this->assertSame('value', $this->container->get('test'));
    }

    public function testDecoratorDelegatesResetToBaseContainer(): void
    {
        $this->container->set('test', fn() => 'value');
        $this->container->get('test'); // Build instance

        $this->assertTrue($this->container->has('test'));

        $this->container->reset('test');

        $this->assertFalse($this->container->has('test'));
    }
}