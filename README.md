<div align="center" style="text-align: center">

![NAF](src/Resources/images/naf-logo-small-square.png)

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

## Documentation

**[Read the documentation →](https://nafphp.github.io/docs/)**

NAF is a small core with plugins around it. What each plugin does, how it is configured and
what it needs is in the documentation. Not sure which packages you need?
[Start here](https://nafphp.github.io/docs/choosing-packages/).

## Install

```bash
composer require naf/framework
```

## License

MIT.


## Unreleased Nafinity integration candidate

Target branch: `v0.2.4-rc`. This behavior is not a published release yet.

Null service results remain registered and cached; object/static event callables retain their supplied target. Detailed error output requires explicit dev mode. HTTP responses stream seekable bodies in bounded chunks. View escaping accepts null as empty text and substitutes malformed UTF-8.
