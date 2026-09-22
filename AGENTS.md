# Working on naf/framework

NAF is a lightweight, functional PHP microframework: "As simple as possible, as flexible as
necessary." This repository is the `naf/framework` Composer **library**. It provides boot,
configuration, the container, routing/dispatch, events, HTTP responses and errors. Application
business rules belong in the host; optional capabilities such as forms, views, auth, sessions,
database access and HTTP clients belong in plugins. Keep the core small and reusable.

Before changing code, read [composer.json](composer.json), [CONTRIBUTING.md](CONTRIBUTING.md),
the [shared workflow](https://github.com/nafphp/docs/blob/main/AGENT_WORKFLOW.md) and
[release procedure](https://github.com/nafphp/docs/blob/main/RELEASING.md). In the multi-repo
workspace the latter files are in sibling `docs/`; use the links in standalone clones.
Preserve others' changes. Source fixes start on the next appropriate version's RC branch
from current `origin/main`, are tested/committed/pushed, and are merged by the maintainer.
Verified documentation-only changes may be merged and published by the agent. Review and
update documentation for every behavior change; a fix request alone does not authorize a release.

## Follow the implementation, not an assumed API

Read the public helper, service implementation and tests together. Some historical docblocks
describe behavior more broadly than the implementation; for example, `get()` does not autowire.

| Concern | Entry points |
|---|---|
| Public functions and lazy app instance | [src/functions.php](src/functions.php), [AppHolder](src/Support/AppHolder.php) |
| Boot, config and installed plugins | [App](src/Core/App.php), [Config](src/Core/Config.php), [CoreFileLoader](src/Support/CoreFileLoader.php), [Plugin](src/Support/Plugin.php) |
| Services and constructor injection | [Container](src/Core/Container.php), [AutoResolvingContainer](src/Decorators/AutoResolvingContainer.php) |
| Request matching and handlers | [Route](src/Core/Route.php), [Dispatcher](src/Core/Dispatcher.php), [RequestParameter](src/Support/RequestParameter.php) |
| Events and HTTP emission | [EventManager](src/Core/EventManager.php), [Event](src/Core/Event.php), [ResponseEmitter](src/Core/ResponseEmitter.php) |
| Errors and built-in fallback views | [ErrorHandler](src/Core/ErrorHandler.php), [Exceptions](src/Exceptions/), [src/view_helpers.php](src/view_helpers.php) |

Runtime support is PHP 8.3+ with the extensions and PSR dependencies declared in the manifest.
Use PSR-7 responses, PSR-11 container contracts and PSR-3 logging. PSR-18 HTTP client behavior
is supplied by `naf/client`, not by this core. Import namespaced functions explicitly;
`simple_render()` is internal and does not replace the optional `naf/view` API.
Prefer existing services, interfaces, configuration, events and plugin registration before
adding abstractions. Do not add application-specific dependencies to the core.

## Boot and extension contracts to preserve

- `app()` lazily constructs `App` with `AutoResolvingContainer(new Container())`. Construction
  boots the application: environment, services, installed plugins, host routes, then HTTP guards.
  Establish the host's `BASE_PATH` before the first call. Register host service overrides before
  `run()`; keep route files free of eager service resolution during boot.
- Composer packages of type `naf-plugin` are discovered through `InstalledVersions`.
  Plugins declare `extra.naf.boot.before` / `after` lists in their own `composer.json`.
  `PluginBootOrder` validates and sorts the complete graph before any bootstrap runs.
  Composer `require` is not a boot edge. Absent optional targets are reported and ignored.
  An optional host `app/plugins.php` or `src/plugins.php` prioritizes installed packages
  and constrains their relative order; conflicts report a cycle instead of breaking an edge.
  Unconstrained ties use package names. Register every plugin before booting any, so lazy
  configuration sees the complete registry. `getPluginBootPlan()` explains the result.
  The resulting plugin order also determines resource precedence; host routes still load last.
  `hasPlugin()` means registered; use `isBooted()` when boot completion matters.
- `CoreFileLoader` chooses the first existing conventional resource. Preserve its explicit
  `app/`/`src/` and view-path precedence and test collision cases. Plugin routes and helper
  files load before that plugin's root bootstrap. Host helper files need explicit loading
  or Composer `autoload.files`; discovery of plugin helpers does not load host helpers.
- Configuration merges core, plugins and host with `array_replace_recursive()`; the host wins
  and numeric arrays merge by index. `config('section:key')` reads nested values; it is not a
  setter. `env()` returns the environment name. `.env.local` replaces `.env` when present;
  runtime environment constants are `dev`, `test` and `prod`.
- `get($id)` retrieves registered/cached services. `make($class)` builds a fresh instance by
  default; `singleton: true` stores it. Bind interfaces and scalar configuration explicitly.
  `set()` clears that binding's cached value, not references held by existing consumers.
  Factories receive the wrapped base container; capture the decorator if they need `make()`.
- The default dispatcher uses constructor injection for controllers, but route arguments
  are named parameters, not action-method injection. Keep `{id}` aligned with `$id`.
  Give every route a unique name; adding an unnamed route after another route is rejected.
  With another container implementation, inspect the dispatcher's non-autowiring fallback.

## Exercise it through a real host

The framework checkout is not an application/document root. In an unused directory, install
`composer require naf/framework`, create `public/` and `app/`, and use these complete files.
They deliberately require only the core and its declared dependencies.

`bootstrap.php` in that host:

```php
<?php
define('BASE_PATH', __DIR__);
require __DIR__ . '/vendor/autoload.php';

use Naf\Core\Event;
use Psr\Http\Message\ResponseInterface;
use function Naf\{app, event};

event()->listen(Event::RESPONSE_HEADER, static fn(ResponseInterface $response) =>
    $response->withHeader('X-Example', 'naf-core'));

app()->run();
```

`public/index.php`:

```php
<?php
require dirname(__DIR__) . '/bootstrap.php';
```

`app/routes.php`:

```php
<?php
use function Naf\{json, redirect, route};

route()->add('GET', '/hello/{name}', static fn(string $name) =>
    json(['hello' => $name]), 'hello');
route()->add('GET', '/', static fn() =>
    redirect(route('hello', ['name' => 'Ada'])), 'home');
```

From the host root, run `APP_ENV=dev php -S 127.0.0.1:8080 -t public`. Verify `/` returns
302 with `Location: /hello/Ada`, and `/hello/Ada` returns JSON plus `X-Example: naf-core`.
Use a current published package for release/install verification; for changes under review,
also exercise the checked-out framework through a disposable host. A test-only Composer path
repository is appropriate there, but must not enter published dependency configuration.

Keep response construction in `response()` and its `json()`/`redirect()`/`refresh()` helpers.
Return PSR-7 responses from handlers; do not emit headers or exit in application examples.
Preserve the protocol/status/reason phrase, repeated headers and immutable `with*()` results.
The example header hook works because `RESPONSE_HEADER` consumes a returned response.
`CONTROLLER_CALLED` and `RESPONSE_SEND` do not replace responses. `dispatchForResponse()`
selects the last response returned; it does not pipe one listener's result into the next.
Inspect payloads and call sites before using an event as an extension point.

## Verify a change

```sh
composer install
composer validate --strict
composer test
```

These are the actual commands; no `analyse` script is declared. The suite uses
[phpunit.xml](phpunit.xml), [tests/bootstrap.php](tests/bootstrap.php),
[NafTestCase](tests/NafTestCase.php), [unit tests](tests/Unit/) and
[host/plugin fixtures](tests/Fixtures/). Check the locked PHPUnit requirements and
[CI workflow](.github/workflows/php.yml) for the development runtime; do not invent a PHP
matrix the workflow does not run. Keep code compatible with the manifest's supported PHP floor.

Add a regression test at the owning boundary: helper results in `FunctionsTest`, service
resolution in `ContainerTest`/`AutoResolvingContainerTest`, route/dispatch behavior in their
suites, and plugin order/resource precedence in `AppTest`/`PluginTest`/`CoreFileLoaderTest`.
Restore global app, environment, handler and timing state touched by tests. The fixture suite
currently sets `APP_ENV=testing`; that is not the public `Environment::TEST` value (`test`).

For boot/emission/error fixes, include a real HTTP or subprocess check: `App::run()` returns
immediately in CLI and `ResponseEmitter::send()`/`emit()` exit the process. A PHPUnit response
object assertion alone does not prove the emitted status line, headers or failure path works.
Keep fixtures isolated and never send real mail or use live external services in smoke tests.

Review affected [user guides](https://nafphp.github.io/docs/), especially
[DI](https://nafphp.github.io/docs/dependency-injection/),
[plugins](https://nafphp.github.io/docs/plugins/) and
[responses](https://nafphp.github.io/docs/request-response/). When APIs, boot behavior or versions
change, update runnable examples and generated references using the docs repository's README.
Publish version-dependent instructions only after the matching package is available, and
report checks and publication accurately.
