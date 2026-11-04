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

This repository is laid out the same way, so it doubles as the example: copy
`src/config/*.json` to start an application, and see `modules/Hello` for a
module with a route, a controller, a console command (`app:hello`) and an auth class (`HelloAuth` refuses every
restricted route; set your own in `main.auth.auth_class`).

A module that cannot work without some environment variable declares it in
its own `main.json` — `app:health` checks it only when the module is loaded:

```json
{ "required_env": ["KSEF_TOKEN"] }
```

## What it enforces

- A route without `"restricted"` is restricted; say `false` for a public one.
- A write (anything but `GET`, `HEAD`, `OPTIONS`) whose `Origin` is neither in
  `main.cors.allowed_origins` nor the host the request came to gets `403`.
  Clients that send no `Origin` are not affected.
- A body that is not a JSON object or array gets `400`, read through
  `Helpers\Json`, unless the controller catches the exception itself.
- `X-HTTP-Method-Override` is ignored.
- Errors never reach the response unless `APP_DEBUG` is set; each failure carries
  an `error_id` to find it by in the log. Traces hold no function arguments.

## Console

Installed as `vendor/bin/console`; it finds the core commands (`app:health`,
`app:config`, `app:cache`) and every `modules/*/Console/*Command.php`.

```bash
php vendor/bin/console app:health
```

## HTTP entry points

The front controllers belong to the application, not to `vendor/`: copy
`pub/` from this package to `src/pub/` once and fix the autoloader path. Each
is a few lines, all the logic stays in the package, so they never need
updating:

```php
// src/pub/entrypoint.php
require_once __DIR__ . '/../../vendor/autoload.php';

$core = new Bluetree\Api\Core\ApiCore();
$core->run();
$core->handleRequest();
```

```php
// src/pub/healthcheck.php
require_once __DIR__ . '/../../vendor/autoload.php';

Bluetree\Api\Core\Health::respond();
```

The healthcheck answers `200` or `503` with one status per check
(`environment`, `cache`, `database:<name>`, `log`, `modules`) — no hosts or
error messages; those are in `app:health`. It opens every configured database
on each call, so keep it off the public internet:

```nginx
location = /healthcheck {
    allow 127.0.0.1;
    allow 172.16.0.0/12;
    deny all;
    fastcgi_param SCRIPT_FILENAME /var/www/html/src/pub/healthcheck.php;
    include fastcgi_params;
    fastcgi_pass api:9000;
}
```

## Development

```bash
docker run --rm -v "$PWD":/app -w /app chajr/php85-dev sh -c 'composer install && composer test'
```

Tests need no cache or database: unreachable ones are what they check against.
