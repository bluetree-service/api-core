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

## Console

Installed as `vendor/bin/console`; it finds the core commands (`app:health`,
`app:config`, `app:cache`) and every `modules/*/Console/*Command.php`.

```bash
php vendor/bin/console app:health
```

## HTTP entry points

`pub/entrypoint.php` handles every API request, `pub/healthcheck.php` answers
`200` or `503` with one status per check (`environment`, `cache`,
`database:<name>`, `log`, `modules`) — no hosts or error messages; those are
in `app:health`. Point php-fpm at them in the vendor directory:

```nginx
location = /healthcheck {
    fastcgi_param SCRIPT_FILENAME /var/www/html/vendor/bluetree-service/api-core/pub/healthcheck.php;
    include fastcgi_params;
    fastcgi_pass api:9000;
}

location / {
    fastcgi_param SCRIPT_FILENAME /var/www/html/vendor/bluetree-service/api-core/pub/entrypoint.php;
    include fastcgi_params;
    fastcgi_pass api:9000;
}
```

The healthcheck opens every configured database on each call, so keep it on
the internal network rather than public.

## Development

```bash
docker run --rm -v "$PWD":/app -w /app chajr/php85-dev sh -c 'composer install && composer test'
```

Tests need no cache or database: unreachable ones are what they check against.
