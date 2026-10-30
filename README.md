# api-core

JSON API microframework shared by `bluetree` and `gry` (`Bluetree\Api\Core`).

```bash
composer require bluetree-service/api-core
```

## What it expects from the application

Paths are resolved from the root project (`Composer\InstalledVersions`), not from the package:

- `src/config/{main,routes,connections,events}.json` — base configuration,
- `modules/<Name>/config/*.json` — module configuration,
- `modules/<Name>/Console/*Command.php` — console commands,
- environment variables `DB_USER`, `DB_PASSWORD`.

A module that cannot work without some environment variable declares it in
its own `main.json` — `app:health` checks it only when the module is loaded:

```json
{ "required_env": ["KSEF_TOKEN"] }
```

Entry points (`src/pub/entrypoint.php`, `bin/console`) stay in the application.
