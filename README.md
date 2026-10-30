# api-core

Mikroframework JSON API wspólny dla `bluetree` i `gry` (`Bluetree\Api\Core`).

```bash
composer require bluetree-service/api-core
```

## Czego oczekuje od aplikacji

Ścieżki liczone są od katalogu projektu głównego (`Composer\InstalledVersions`), nie od paczki:

- `src/config/{main,routes,connections,events}.json` — konfiguracja bazowa,
- `modules/<Nazwa>/config/*.json` — konfiguracja modułów,
- `modules/<Nazwa>/Console/*Command.php` — komendy konsoli,
- zmienne środowiskowe `DB_USER`, `DB_PASSWORD`.

Moduł, który bez jakiejś zmiennej środowiskowej nie działa, deklaruje ją w
swoim `main.json` — `app:health` sprawdzi ją tylko wtedy, gdy moduł jest
załadowany:

```json
{ "required_env": ["KSEF_TOKEN"] }
```

Punkty wejścia (`src/pub/entrypoint.php`, `bin/console`) zostają w aplikacji.
