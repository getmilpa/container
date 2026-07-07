<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Container

> The **reference dependency injection container** for the Milpa PHP framework, built on **`milpa/core`**. It implements `milpa/core`'s `DIContainerInterface` with reflection autowiring, lazy singleton resolution, and circular-dependency detection that reports the full chain — on top of a PSR-11 surface backed by Symfony's `ContainerBuilder`.

[![CI](https://github.com/getmilpa/container/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/container/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/container.svg)](https://packagist.org/packages/milpa/container)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-API%20reference-blue.svg)](https://getmilpa.github.io/container/)

`milpa/container` is the concrete `DIContainer` behind `milpa/core`'s
`Milpa\Interfaces\Di\DIContainerInterface` — the contract every Milpa component codes
against. **No product coupling, no service-definition config format of its own**: register
services by hand, or let the container find them by reflection.

## Install

```bash
composer require milpa/container
```

## What it guarantees, beyond the interface

`DIContainerInterface` is deliberately conservative: it documents auto-resolution of
`get()`/`has()` as a **MAY**, not a MUST — a minimal, spec-conformant implementation is
allowed to throw/return `false` for anything not explicitly registered via
`registerService()`. `resolve()` is the one method whose contract *is* auto-wiring for
every implementation.

`DIContainer` exercises that MAY. Concretely, this implementation guarantees:

- **`get()` and `has()` auto-resolve** any existing, non-abstract, non-interface class
  whose constructor dependencies are themselves resolvable, recursively — not just
  identifiers registered via `registerService()`.
- **Auto-resolved classes are cached as singletons** on first resolution: later `get()`
  calls for the same identifier return the same instance, unless `resolve()` was called
  directly with `$singleton = false`.
- **Circular dependencies are detected and reported with the full chain** — `A` needs `B`
  needs `A`, directly or transitively, throws `CircularDependencyException` with every
  class name in the cycle, not just a generic "circular dependency" message.
- **`tryGet()` never throws** — it returns `null` for anything unregistered and
  unresolvable, instead of propagating `ServiceNotFoundException` or
  `ContainerResolutionException`.
- **Non-resolvable classes are cached as such** (interfaces, abstract classes, classes
  with unresolvable constructor parameters), so repeated lookups don't re-run reflection.

If you need one of these guarantees, depend on `DIContainer` (or its documented behavior)
directly — `DIContainerInterface` alone does not promise them.

## Quick example

```php
use Milpa\Container\DIContainer;

class Logger
{
    public function log(string $message): void
    {
        echo $message . "\n";
    }
}

class Greeter
{
    public function __construct(private Logger $logger)
    {
    }

    public function greet(string $name): void
    {
        $this->logger->log("Hello, {$name}!");
    }
}

$container = new DIContainer();

// Auto-wiring: no registerService() call needed — Greeter's constructor
// dependency (Logger) is resolved recursively.
$greeter = $container->get(Greeter::class);
$greeter->greet('World'); // "Hello, World!"

// get() caches auto-resolved classes as singletons.
$container->get(Greeter::class) === $greeter; // true

// tryGet() never throws — null for anything unregistered/unresolvable.
$container->tryGet('Nonexistent\Service'); // null

// Explicit registration still wins over auto-wiring for the same id.
$container->registerService(Logger::class, new Logger());
```

Circular dependencies fail loudly, with the chain that caused them:

```php
class A { public function __construct(public B $b) {} }
class B { public function __construct(public A $a) {} }

$container->get(A::class);
// throws Milpa\Exceptions\CircularDependencyException:
// "Circular dependency detected while resolving: A -> B -> A."
```

## What lives where

| Layer | Package | Owns |
|-------|---------|------|
| Contracts | `milpa/core` | `DIContainerInterface` (extends PSR-11 `ContainerInterface`), `ServiceNotFoundException`, `ContainerResolutionException`, `CircularDependencyException` — the seam, not the engine. |
| **Implementation** | **`milpa/container`** (this package) | The concrete `DIContainer`: reflection autowiring, singleton caching, circular-dependency detection, and safe (`tryGet()`) retrieval on top of Symfony's `ContainerBuilder`. |
| Your app | your host / plugins | Wiring decisions — which services to register explicitly vs. leave to autowiring, and when to call `compileContainer()`. |

## Requirements

- PHP **≥ 8.3**
- [`milpa/core`](https://packagist.org/packages/milpa/core) **^0.3**
- [`psr/container`](https://packagist.org/packages/psr/container) **^2.0**
- [`symfony/dependency-injection`](https://packagist.org/packages/symfony/dependency-injection) **^7.0**

## Documentation

**Full API reference: [getmilpa.github.io/container](https://getmilpa.github.io/container/)** — generated
straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © TeamX Agency.

---

Milpa is designed, built, and maintained by **[TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=container)**.
