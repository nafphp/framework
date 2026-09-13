<div align="center" style="text-align: center">

![Logo](https://nafphp.github.io/docs/assets/naf-logo-small-square.png)

[![NAF Build & Test](https://github.com/nafphp/framework/actions/workflows/php.yml/badge.svg)](https://github.com/nafphp/framework/actions/workflows/php.yml)

</div>

---

# NAF

> **"As simple as possible, as flexible as necessary."**

**NAF** is a modern, lightweight PHP microframework designed for real-world projects:  
fast, minimal, extendable, and now fully embracing modern PHP standards like PSR-3, PSR-4, PSR-7, PSR-11, and PSR-18.

It builds on native PHP features and lets you stay in control:  
**Use only what you need, and extend freely when you want.**

> 🧩 NAF provides a minimal core with a clean plugin architecture.  
> Everything beyond routing and dispatching, such as sessions, views, forms, or database, is handled by optional plugins.  
> You get full control over what your app includes, and nothing more.

---

## ✨ Philosophy

- **Minimalist Core**: Only essential components by default.
- **PSR-First**: Native support for key PHP standards (PSR-3, PSR-4, PSR-7, PSR-11, PSR-18).
- **Extendable**: Easily plug in external libraries — Blade, Twig, Eloquent, Middleware, etc.
- **Transparent by Design**: No hidden magic, no complicated abstractions.
- **Native PHP Power**: PDO database, clean routing, lightweight templating.
- **Secure and Clear Structure**: Safe public directory (`public/`) separated from your app code.

---

## Core Features

- **Plugin System**: Add reusable features via Composer
- **Lightweight Routing**: Define routes with `[Controller::class, 'method']`
- **Smart Dispatcher**: Automatic parameter and controller resolution
- **Dependency Container (PSR-11)**: Service registry with built-in autowiring
- **PSR-3 Logging** (lightweight logger ready to use)
- **PSR-4 Autoloading** (Composer)
- **PSR-7 Request/Response Handling**
- **PSR-18 HTTP Client** (via `naf/client`)
- **Minimalist View System**: Block-based templating (via `naf/view`)
- **PDO Database Connection** (via `naf/database`)
- **Session Handling** (via `naf/session`)
- **Form Memory Helpers** (via `naf/form`)
- **JSON Response Helper** (for easy API responses)
- **Composer-Ready**: Easy installation and dependency management

---

## PSR Compliance Overview

| PSR | Description | Status                             |
|:---|:---|:---------------------------------------------|
| PSR-3 | Logger Interface | ✅ Integrated               |
| PSR-4 | Autoloading Standard | ✅ Native via Composer  |
| PSR-7 | HTTP Message Interface | ✅ Integrated         |
| PSR-11 | Container Interface | ✅ Integrated           |
| PSR-18 | HTTP Client Interface | ✅ Integrated         |


---

## ❓ Why not just use Laravel or Symfony?

Frameworks like Laravel and Symfony are fantastic, but they often come with a heavy stack of features, conventions, and dependencies you may not always need.

**NAF** offers a different approach:

- **No hidden complexity**: You see exactly what happens.
- **No forced patterns**: Use only what you need, when you need it.
- **No unnecessary overhead**: Keep performance and flexibility under your control.
- **Real extendability**: Bring your favorite libraries if needed — but stay light if you don't.

If you want full control without fighting against a "big framework" structure,  
**NAF** might be the perfect starting point for you.

---

# 📢 Installation

## Install via Composer
```bash
composer require naf/framework
```

This installs the **NAF core**, a minimal routing and dispatch layer.  
For additional features like views, forms, or sessions, just install the corresponding plugins.

---

## Set up your project structure

NAF leaves the project organization completely up to you.  
A typical structure could look like this:
```
/app
    /Controllers
    /Models
    /Services
    config.php
    routes.php
/public
    index.php
bootstrap.php
composer.json
```

- **Plural names** for app folders
- **`public/`** as webroot for higher security
- **`bootstrap.php`** for autoloading and bootstrapping services, registering events, and so on...

---

## Creating your App

You typically...

- Create a `bootstrap.php` to initialize NAF
- Set up your `routes.php`
- Create a `public/index.php` as your web entry point (which includes bootstrap.php)

1. **Fill bootstrap.php**
```php
// /bootstrap.php

define('BASE_PATH', __DIR__);

require __DIR__ . '/../vendor/autoload.php';

use function Naf\app;

app()->run(); // Start the application
```

2. **Create a route**
```php
//File: app/routes.php

router()->add('GET', '/hello', [HelloController::class, 'index']);
```

3. **Create a controller**
```php
//File: app/Controllers/HelloController.php

namespace App\Controllers;

class HelloController
{
    public function index()
    {
        return view('hello', ['name' => 'World']);
    }
}
```

4. **Create a view**
```php
//File: app/views/hello.phtml

use function Naf\s; // Sanitize on output (provided through naf/view)

<h1>Hello, <?= s($name) ?>!</h1>
```

5. **Access your page**

Visit:
```
http://your-app.local/hello
```

You should see:
```
Hello, World!
```

---

## 🔧 Dependency Injection & Autowiring

NAF includes a **PSR-11 compliant container** with **automatic dependency resolution** built-in.  
No configuration needed, it just works.

### Registering Services

Register your core services (interfaces, databases, loggers) in the container:
```php
use function Naf\app;

// Register interfaces (required for autowiring)
app()->container()->set(LoggerInterface::class, fn() => new FileLogger());
app()->container()->set(DatabaseInterface::class, fn() => new MySQLDatabase());
```

### Automatic Dependency Resolution

Controllers and services automatically receive their dependencies:
```php
class UserService {
    public function __construct(
        private LoggerInterface $logger  // ✅ Automatically injected
    ) {}
}

class UserController {
    public function __construct(
        private UserService $service,    // ✅ Auto-built and injected
        private LoggerInterface $logger  // ✅ Retrieved from container
    ) {}
}

// No manual wiring needed - the dispatcher handles it automatically!
router()->add('GET', '/users', [UserController::class, 'index']);
```

### How It Works

NAF's autowiring follows these basic rules:

1. **Interfaces must be registered**: tell the container which implementation to use
2. **Concrete classes are auto-built**: no registration needed
3. **Dependencies are resolved recursively**: the entire dependency tree is handled

### Building Instances Manually

Sometimes you need to build instances directly (e.g., Commands):
```php
// Build a fresh instance
$command = app()->container()->make(MigrateCommand::class);

// Build with custom parameters
$handler = app()->container()->make(RequestHandler::class, [
    'timeout' => 30,
    'retries' => 3
]);

// Build as a singleton (stored in the container for reuse)
$service = app()->container()->make(CacheService::class, singleton: true);
```

### Features

- **Zero configuration**: autowiring works out of the box
- **Concrete classes auto-resolve**: less boilerplate
- **Circular dependency detection**: prevents infinite loops
- **Nullable dependencies**: handled gracefully
- **Optional parameters**: with default values supported
- **Custom parameters**: pass explicit values when needed

### Best Practices

| What to Register | Why |
|:-----------------|:----|
| Interfaces (e.g., `LoggerInterface`) | Required for autowiring |
| Database connections | Singleton configuration |
| Third-party services | Complex initialization |
| Configuration objects | Share across application |

| What NOT to Register | Why |
|:--------------------|:----|
| Controllers | Auto-built by dispatcher |
| Simple services | Auto-resolved on demand |
| Value objects | Created as needed |
| Commands | Built via `make()` |

---

## 🔌 Plugin Support

NAF includes a clean plugin system that allows you to extend your app modularly — without configuration.

Just install a plugin via Composer (e.g. `composer require vendor/my-plugin`) and it is automatically detected if it uses the correct package type:
```json
{
  "type": "naf-plugin"
}
```

A typical plugin might look like this:
```
my-plugin/
├── src/
│   └── config.php
├── views/
│   └── errors/404.phtml
├── bootstrap.php
└── composer.json
```

- `config.php` is automatically merged.
- `views/` are added to the view search path.
- `bootstrap.php` runs automatically to register routes, events, etc.

### Layout conventions

Wherever a resource is looked up, both the `app/` and the `src/` spelling are
accepted. Views may additionally live in a top-level `views/` directory, which
keeps template files out of your PSR-4 source root.

Which resources exist differs between a plugin and your application:

| Resource           | In a plugin                       | In your application |
|--------------------|-----------------------------------|---------------------|
| `config.php`       | `app/`, `src/`                    | `app/`, `src/`      |
| `routes.php`       | `app/`, `src/`                    | `app/`, `src/`      |
| `plugins.php`      | —                                 | `app/`, `src/`      |
| `functions.php`    | `app/`, `src/`                    | —                   |
| `view_helpers.php` | `app/`, `src/`                    | —                   |
| views              | `src/views`, `views`, `app/views` | `views`, `app/views` |
| `bootstrap.php`    | package root only                 | —                   |

`bootstrap.php` is the one exception to the two spellings: it is always read
from the package root, never from `app/` or `src/`.

The first existing candidate wins and the rest are ignored, so a package should
never ship two of them at once. Resources that do not exist are not registered
at all — `getViewPaths()` and friends only ever return real paths.

Application view paths are resolved by `naf/view` and are configurable via
`view.paths`; the other application resources are loaded by the core.

### Plugin availability checks

Use `app()->hasPlugin('vendor/name')` to gate functionality on optional plugins. Version hints like `vendor/name:>=0.1.2` or `vendor/name:<0.2.0` are also supported — the constraint is compared against the plugin version that Composer registers when the plugin loads. This makes it easy to only enable code when a specific plugin version is present, without having to interrogate `InstalledVersions` manually.

You can build plugins exactly like you build an app, with full access to routing, events, and controllers.

> For plugin examples, see the [Plugin Documentation](https://nafphp.github.io/docs/plugins/)

---

# License

MIT License.