# Kalorie

Konrad's Cal AI clone: photograph a meal, get the foods, portions, calories and macros.
Runs on Serverus; meal analysis uses Claude on Konrad's own subscription.

## Parts

- `src/`, `config/`: Symfony 8.1 JSON API under `/api` (PHP 8.5, Doctrine, SQLite).
  Bearer tokens (`Authorization: Bearer …`), so the web app and a future iOS app use the same API.
- `public/`: the web app (plain ES modules, no build step), mobile first, Polish.
- `bridge/claude_bridge.py`: small host service. Claude Code is logged in on the host,
  not in the container, so the app reaches it through a Unix socket: the bridge runs
  `claude -p` with no tools, no settings, no CLAUDE.md, the app's system prompt, the
  photo and a JSON schema, and returns the structured answer.
- `deploy/`: compose file for `/opt/kalorie` and the bridge's systemd user unit.

Meal analysis is behind `App\Ai\MealAnalyzer`; `KALORIE_ANALYZER` picks the engine
(`claude` = the bridge, `fake` = fixed answers for tests). The prompt and schema live in
`App\Ai\MealPrompt`, shared by all engines. An Anthropic API engine (needed if the app
is ever used by people other than Konrad: a personal subscription is for personal use)
or a local vision model is one more class.

## API

| Method | Path | |
|---|---|---|
| POST | `/api/auth/register`, `/api/auth/login`, `/api/auth/logout` | `{email, password}` → `{token, user}` |
| GET/PUT | `/api/me`, `/api/me/profile`, `/api/me/goals`, `/api/me/password` | profile → computed goals (Mifflin-St Jeor); `{auto: true}` resets manual goals |
| POST | `/api/meals/photo` | multipart `photo`, `note?`, `eaten_at?` |
| POST | `/api/meals/describe` | `{text, eaten_at?}` |
| POST | `/api/meals` | manual: `{name, items[], eaten_at?}` |
| GET/PATCH/DELETE | `/api/meals/{id}` | PATCH: `name`, `eaten_at`, `items` |
| POST | `/api/meals/{id}/fix` | `{text}`: the AI corrects its analysis |
| POST | `/api/meals/{id}/copy` | log the same meal again |
| GET | `/api/meals/{id}/photo[?size=thumb]` | |
| GET | `/api/days/{YYYY-MM-DD}`, `/api/summary?days=7&end=` | |
| GET/POST/DELETE | `/api/weights`, `/api/weights/{day}` | |
| GET | `/api/health` | database + bridge |

Errors: `{"error": {"code", "message"}}`, the message in Polish, ready to show.

## Settings (env)

`KALORIE_REGISTRATION` (`first` = only the first account, `open`, `closed`),
`KALORIE_MODEL` (`sonnet` ~6 s per photo, `opus` ~12 s, `haiku`),
`KALORIE_ANALYSES_PER_HOUR` (default 40 per user), `KALORIE_PHOTO_DIR`, `DATABASE_URL`.

## Develop

```
docker build --target dev -t kalorie-php:dev -f docker/php.Dockerfile .
docker run --rm -u 1000:1000 -e HOME=/tmp -v $PWD:/app -w /app kalorie-php:dev composer install
docker run --rm -u 1000:1000 -e HOME=/tmp -v $PWD:/app -w /app kalorie-php:dev php bin/phpunit
```

Tests use the fake analyzer; no Claude calls.

## Photos and privacy

Every upload is decoded and re-encoded as JPEG (upright, max 1600 px, plus a 400 px
thumbnail), so EXIF data such as GPS position is never stored or sent to Claude. The web
app also shrinks photos on the phone before upload (and turns HEIC into JPEG).
