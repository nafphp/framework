# Contributing to NAF

Thanks for your interest in contributing to **NAF** – a lightweight PHP microframework built for simplicity and flexibility. 🙌

Whether you're reporting a bug, suggesting a feature, or submitting a pull request — you're welcome here.

Read [AGENTS.md](AGENTS.md) for the code map, extension contracts, executable host example
and the shared contribution/release workflow. It records the maintainer's branch, merge
and documentation publication instructions for this repository.

---

## 🧠 Philosophy

NAF follows a simple rule:

> **"As simple as possible, as flexible as necessary."**

Please keep this in mind when proposing changes.  
We want to keep the core minimal, readable, and extensible — without adding unnecessary abstractions or complexity.

---

## 💡 Suggestions & Issues

If you have a feature idea or found a bug:
- Open an issue in [GitHub Issues](https://github.com/nafphp/framework/issues)
- Be as specific as possible
- Screenshots or code examples help a lot

---

## 🔧 Pull Requests

When submitting a PR:

- Keep changes focused and clean
- Avoid large architectural rewrites
- Don't introduce third-party dependencies unless discussed
- Use native PHP wherever possible

If in doubt — open an issue first to discuss your idea.

---

## 📁 Project Structure

The framework consists of:

- `src/Core/` — central framework logic (router, dispatcher, etc.)
- `src/Support/` — utility classes and helpers
- `src/Resources/` — internal views and templates
- `src/Exceptions/` — custom exception classes
- `src/Decorators/` — constructor autowiring around the base container
- `src/functions.php` — public functions in the `Naf` namespace
- `src/view_helpers.php` — internal fallback rendering helpers

Classes use Composer PSR-4 autoloading. The two helper files load through `autoload.files`;
application code imports public functions explicitly with `use function`.

---

## 🧪 Tests

The PHPUnit suite lives in `tests/`, with shared bootstrap/configuration and application/plugin
fixtures. Install development dependencies, validate the manifests and run it:

```sh
composer install
composer validate --strict
composer test
```

Add regression coverage for behavior changes. Boot, response emission and error handling
also need a real HTTP/subprocess check; an in-process response object test does not verify
headers on the wire. There is currently no Composer `analyse` script. Consult
[AGENTS.md](AGENTS.md) for test boundaries and documentation verification.

---

## 📄 License

By contributing, you agree that your code will be licensed under the [MIT License](LICENSE).

---

Thanks again - you're awesome. 🚀  
Let's build something great with PHP.
