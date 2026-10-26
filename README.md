# Kalorie

A self-hosted calorie tracker in the spirit of Cal AI: photograph a meal (or describe it)
and get the foods, portions, calories and macros back. It also suggests what to eat next
to fill the rest of your day, and keeps a simple training log.

The app's interface is in Polish. The code, API and docs are in English.

## Features

- **Meals from a photo, a description or by hand.** The AI splits a meal into items with
  grams, kcal, protein, carbs and fat. You can edit any item (changing the grams rescales
  the values) or tell the AI what's wrong ("to były 2 kotlety").
- **Meals from a recipe with known values.** Pick a recipe (search by name or ingredient,
  recently eaten ones first), say how much (½, 1, 1½, 2 portions or grams) and add a photo
  if you like. The values come from the recipe, not from the AI. The recipes are the Respo
  diet plans, kept outside the repo (see `KALORIE_RECIPES_FILE`).
- **"Co zjeść?" (what should I eat?).** Three meal ideas that fill what's left of today's
  calories and macros, based on the time of day, today's training and the meals you
  usually log. A macro that's already used up counts as a limit. One tap logs the meal
  you pick.
- **Training log.** Once a day the app asks whether you're training: a short
  description, an optional part of day and intensity, or a rest day. A day can hold
  several trainings (yoga in the morning, gym in the evening). Your most frequent descriptions
  show up as quick picks. For now it's context for suggestions; later it's meant to
  help adjust the daily goal from your real intake and weight trend.
- **Goals:** computed from your profile (Mifflin-St Jeor × activity, deficit or surplus
  for your goal, 1.6–2.2 g protein per kg, 27% fat), or set by hand. Goals: lose, maintain,
  body recomposition (−10% up to 300 kcal, 2.2 g/kg protein), gain. Settings show how the
  number was worked out.
- **Progress:** weight log with a chart, 14-day calorie chart, week strip.
- **Installable web app:** mobile first, light and dark mode, no build step.
- **One JSON API** with bearer tokens, so a native iOS app can use it later.

## How it works

```
phone ──HTTPS──> Caddy ──> nginx ──> PHP-FPM (Symfony API, SQLite, photos)
                                        │
                                        │ Unix socket
                                        ▼
                         bridge/claude_bridge.py (on the host)
                                        │
                                        ▼
                                 claude -p (Claude)
```

- **API** (`src/`, `config/`): Symfony 8.1 on PHP 8.5, Doctrine ORM with SQLite. JSON
  only, under `/api`. Errors are `{"error": {"code", "message"}}`, the message in Polish
  and ready to show.
- **Web app** (`public/`): plain ES modules and CSS, no framework or bundler.
- **AI engine:** `App\Ai\MealAnalyzer` (`analyze()` for meals, `suggest()` for
  suggestions), picked by `KALORIE_ANALYZER`:
  - `claude`: Claude through the host bridge (below).
  - `fake`: fixed answers, for tests and for trying the app offline.

  Prompts and JSON schemas live in `App\Ai\MealPrompt` and `App\Ai\SuggestionPrompt`
  and are shared by every engine. Adding an Anthropic API engine or a local vision model
  means adding one class.
- **Bridge** (`bridge/claude_bridge.py`, Python stdlib only): a small host service for
  setups where Claude Code is logged in on the host rather than in the container. It
  runs `claude -p` with **no tools, no MCP servers, no settings or CLAUDE.md and no
  session files**: just the app's system prompt, the content (text and photo) and a JSON
  schema. It allows at most 2 runs at once with a 180 s cap, and maps usage-limit errors
  to a clear message.

  If you run this for anyone other than yourself, use the Anthropic API instead. A
  personal Claude subscription is for personal use.

Typical timings with `sonnet`: about 6 s per photo, about 20–25 s for three suggestions.

## API

| Method | Path | |
|---|---|---|
| POST | `/api/auth/register`, `/api/auth/login`, `/api/auth/logout` | `{email, password}` → `{token, user}` |
| GET/PUT | `/api/me`, `/api/me/profile`, `/api/me/goals`, `/api/me/password` | profile → computed goals (`goal_breakdown` in the user); `{auto: true}` resets manual goals |
| POST | `/api/meals/photo` | multipart `photo`, `note?`, `eaten_at?` |
| POST | `/api/meals/describe` | `{text, eaten_at?}` |
| POST | `/api/meals/recipe` | multipart `recipe_id`, `portions?` (default 1) or `grams?`, `eaten_at?`, `photo?`; no AI call |
| GET | `/api/recipes?q=` | `{recipes[], recent}`: up to 30 matches by name, then by ingredient; without `q`, the recipes you logged lately |
| POST | `/api/meals` | `{name, items[], eaten_at?, source?}`; `source` is `manual` (default) or `suggestion` |
| GET/PATCH/DELETE | `/api/meals/{id}` | PATCH: `name`, `eaten_at`, `items` |
| POST | `/api/meals/{id}/fix` | `{text}`: the AI corrects its analysis |
| POST | `/api/meals/{id}/copy` | log the same meal again |
| GET | `/api/meals/{id}/photo[?size=thumb]` | |
| POST | `/api/suggestions` | `{note?}` → `{remaining, suggestions[3], notes}`; nothing is saved |
| GET | `/api/workouts?days=30` | `{entries[], frequent[]}` |
| POST | `/api/workouts/{YYYY-MM-DD}` | one more training `{description, intensity?, part_of_day?}`, or `{rest: true}` while the day has none |
| PATCH/DELETE | `/api/workouts/{id}` | edit (replaces description, intensity, part_of_day) / delete one entry |
| PUT | `/api/workouts/{YYYY-MM-DD}` | older app versions: replaces the whole day with one entry |
| GET | `/api/days/{YYYY-MM-DD}` | goals, totals, remaining, meals, weight, workouts[] |
| GET | `/api/summary?days=7&end=` | per-day totals |
| GET/POST/DELETE | `/api/weights`, `/api/weights/{day}` | |
| GET | `/api/health` | database and bridge status |

AI calls (photo, description, correction, suggestions) share a per-user hourly cap.

## Configuration (environment)

| Variable | Default | |
|---|---|---|
| `APP_SECRET` | | required in production; keep it out of git |
| `DATABASE_URL` | `sqlite:///%kernel.project_dir%/var/data/kalorie.db` | |
| `KALORIE_PHOTO_DIR` | `var/data/photos` | |
| `KALORIE_ANALYZER` | `claude` | `claude` or `fake` |
| `KALORIE_BRIDGE_SOCKET` | `/run/kalorie/ai.sock` | |
| `KALORIE_MODEL` | `sonnet` | `sonnet`, `opus` (slower, slightly better), `haiku` |
| `KALORIE_REGISTRATION` | `first` | `first` = only the first account, `open`, `closed` |
| `KALORIE_ANALYSES_PER_HOUR` | `40` | per user |
| `KALORIE_RECIPES_FILE` | `var/data/recipes.json` | recipes for "Z przepisu"; without the file there are none |

## Recipes file

`bin/respo-recipes` builds it from the Respo plan notes in Konrad's vault (one note per
diet plan, parsed from the PDFs): values per portion, one entry per recipe, ids `Pnn-Dd-m`.

```sh
bin/respo-recipes ~/AI-Brain/"04 - Personal/Respo Recipes" /opt/kalorie/data/recipes.json
```

The recipes aren't ours to publish, so the file is never committed; tests use the made-up
`tests/fixtures/recipes.json`. The app reads the file on each request, so a new one needs no
restart.

## Development

Everything runs in Docker; no local PHP is needed.

```sh
docker build --target dev -t kalorie-php:dev -f docker/php.Dockerfile .
docker run --rm -u 1000:1000 -e HOME=/tmp -v $PWD:/app -w /app kalorie-php:dev composer install
docker run --rm -u 1000:1000 -e HOME=/tmp -v $PWD:/app -w /app kalorie-php:dev php bin/phpunit
```

The tests use the fake engine and never call Claude.

`bin/check` runs every check in Docker (the dev image; the UI tests also use the Playwright image) (composer validate, `lint:container`, `lint:yaml`, phpstan, PHPUnit, UI tests) and exits non-zero if any fails.

UI tests (`tests/ui/*.test.mjs`, `node:test` + headless Chromium at iPhone size) run with `bin/ui-test [file]`: a throwaway backend (`php -S`, fake analyzer, database in the container) and the Playwright image on a private Docker network, both removed afterwards.

## Deployment

`deploy/compose.yaml` runs two containers (`kalorie-php`, `kalorie-nginx`) from a
directory such as `/opt/kalorie`:

```
/opt/kalorie/
  app/       a clone of this repo (built into the PHP image; nginx serves app/public)
  data/      SQLite database and photos (back this up)
  run/       the bridge's socket
  app.env    APP_SECRET (mode 600, not in git)
```

The bridge runs on the host as a systemd user service (`deploy/kalorie-ai.service`).
Put a TLS reverse proxy (e.g. Caddy) in front of `kalorie-nginx`. Database migrations
run automatically when the PHP container starts.

To update: pull the repo in `app/`, then `docker compose build kalorie-php && docker
compose up -d`.

## Privacy

Photos are decoded and re-encoded on upload (upright, max 1600 px plus a 400 px
thumbnail), so EXIF metadata such as GPS position is never stored or sent to the AI. The
web app also shrinks photos on the phone first. The page loads no third-party scripts,
fonts or trackers. See [PRIVACY.md](PRIVACY.md) for what is stored and what goes to the
AI provider.

## License

Copyright © 2026 Konrad Bieniek. Licensed under the
[GNU Affero General Public License v3.0 or later](LICENSE): you may use, change and
share this code, and if you run a modified version as a network service, you must offer
its source to the people who use it.
