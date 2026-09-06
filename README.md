# FamilyHub

A family wall-calendar app for a single household — a self-hosted replacement for
Skylight / Hearth. It runs on an always-on wall-mounted iPad (Safari PWA in Guided
Access) and on family phones.

**Stack:** Laravel 13 · Livewire 4 · Alpine · Tailwind 4 · MySQL · Redis

> The original brief specified Laravel 11 + Livewire 3 + Breeze. That was changed
> to Laravel 13 + Livewire 4 because Laravel 11 left its security-fix window in
> March 2026 and this app is internet-facing. Breeze is not used: it hard-pins
> Livewire 3 and Tailwind 3. Auth is a login-only surface — there is no
> registration route by design.

---

## Status

| Phase | Scope | State |
| ----- | ----- | ----- |
| **1** | Foundation, models, wall display, PWA | **Done** |
| 2 | Calendar sync — Google OAuth + iCloud CalDAV, two-way | Not started |
| 3 | Capture — inbound email, photo/PDF/URL, Claude extraction, review queue | Not started |
| 4 | Kids — chores, routines, rewards | Not started |
| 5 | Meals & shopping | Not started |
| 6 | Home Assistant, weather, bins, AI assistant | Not started |

### What Phase 1 ships

- Household, Member, CalendarAccount, Calendar, Event, Checklist, ChecklistItem models
- `/display` — the wall display: per-member agenda columns, week strip, upcoming
  rail, lists, idle photo screensaver, scheduled dark mode
- `/app` — phone view with a per-member filter
- `/admin` — household and member settings, display pairing
- `/login` — email + password, rate limited. No registration route.
- PWA: manifest, offline-shell service worker, iOS standalone meta, safe-area
  handling, rubber-band suppression

Day and tab switching on the wall are pure Alpine — **zero** server round trips.
Livewire re-renders on a 60-second poll to pick up edits made from phones.

---

## Local setup

Requires PHP 8.3+, Composer, Node 20+, MySQL and Redis. (MySQL and Redis via
DBngin, Herd or Docker are all fine.)

```sh
composer install
npm install

cp .env.example .env
php artisan key:generate

# Create the database, then:
php artisan migrate
php artisan storage:link

# Seeds the household and members from FAMILYHUB_SEED_* in .env,
# plus a fortnight of demo calendar content.
php artisan familyhub:seed-demo

npm run build     # or: npm run dev
php artisan serve
```

Sign in with the credentials in `FAMILYHUB_SEED_USERS`. **Change the seeded
password immediately** — it is in plain text in your `.env`.

### Tests

```sh
php artisan test   # PHP: models, routing, auth, display, timezone
npm test           # JS: dark-mode schedule
```

---

## Time handling

Everything is **stored in UTC**. `FAMILYHUB_TIMEZONE` (default `Europe/London`)
is the zone times are *displayed* in, and — more importantly — the zone whose
midnight decides which day an event belongs to. An event at 00:30 London is
23:30 UTC the previous day; bucketing on the raw UTC value would file it under
the wrong day on the wall.

`App\Casts\UtcDateTime` enforces this on the way into the database. Do not swap
it for Eloquent's built-in `datetime` cast: that one writes the wall-clock value
of whatever Carbon instance it is handed and silently discards the zone.

---

## Wall display setup (iPad)

### 1. Pair the device

```sh
php artisan familyhub:display-token
```

Open the printed URL **once** on the iPad. The token is exchanged for a
year-long cookie and stripped from the address bar; a copy is kept in
`localStorage` so the iPad can re-pair itself if Safari ever clears its cookies.

`php artisan familyhub:display-token --new` rotates the token. Any paired iPad
will need re-pairing.

### 2. Add to Home Screen

Safari → Share → **Add to Home Screen**. Launch it from the icon, not from
Safari, so it runs full-screen with no browser chrome.

### 3. Settings → Display & Brightness

- **Auto-Lock: Never** (Settings → Display & Brightness → Auto-Lock)
- Turn **True Tone** off so the colours don't drift through the day
- Keep the iPad on a charger; a wall-mounted tablet is always plugged in

### 4. Guided Access

Settings → Accessibility → **Guided Access** → on. Set a passcode. Then triple-click
the side/home button inside the app and tap **Start**. This stops small hands from
leaving the app.

### 5. Brightness schedule (Shortcuts)

The app dims itself on schedule (`FAMILYHUB_DARK_START` / `FAMILYHUB_DARK_END`,
default 21:00–06:30), but the iPad backlight is separate. In the Shortcuts app →
Automation:

- **Time of Day 21:00** → Set Brightness to 15% → Run Immediately
- **Time of Day 06:30** → Set Brightness to 80% → Run Immediately

### 6. Screensaver photos

Drop images into `storage/app/public/photos` (or the `FAMILYHUB_PHOTOS_PATH`
prefix of your S3 bucket). The display fades to them after
`FAMILYHUB_IDLE_MINUTES` of no touches; any tap wakes it. With no photos present
it falls back to a large, quiet clock.

> `storage/` is not tracked by git, so the photos folder starts empty on a fresh
> clone. The screensaver shows the clock until you put images in it.

---

## Deployment (Forge / DigitalOcean)

Set `APP_ENV=production`, `APP_DEBUG=false` and `APP_URL=https://hub.yourdomain.com`.

```sh
php artisan config:cache
php artisan route:cache
php artisan view:cache
npm run build
```

Phase 2 adds Horizon and the scheduler under supervisor; until there are queued
jobs to run, neither is needed.

---

## Integration setup

These cover later phases. The `.env` keys already exist and are documented in
`.env.example`.

### Google Calendar OAuth — *Phase 2*

1. Google Cloud Console → new project → enable the **Google Calendar API**
2. OAuth consent screen → **External**, publishing status **Testing** is fine for
   a household; add each family Google account as a test user
3. Credentials → Create → **OAuth client ID** → Web application
4. Authorised redirect URI: `https://hub.yourdomain.com/admin/calendars/google/callback`
5. Put the client ID and secret in `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`

Push notifications (`events.watch`) require a publicly reachable HTTPS endpoint;
a 5-minute poll is the fallback.

### iCloud CalDAV — *Phase 2*

1. [appleid.apple.com](https://appleid.apple.com) → Sign-In and Security
2. **App-Specific Passwords** → generate one, label it "FamilyHub"
3. Add the account in `/admin` with the Apple ID and that password — never the
   real Apple ID password

Credentials are stored encrypted (`CalendarAccount::$credentials` is an
`encrypted:array` cast) and never appear in JSON output.

### Postmark inbound email — *Phase 3*

1. Postmark → your server → **Inbound** stream
2. Set the inbound webhook to
   `https://hub.yourdomain.com/webhooks/postmark/{POSTMARK_INBOUND_SECRET}`
3. Note the inbound address Postmark gives you and forward school newsletters to it
4. Set `POSTMARK_TOKEN` and `POSTMARK_INBOUND_SECRET`

### Home Assistant — *Phase 6*

HA is the **only** smart-home driver. Zigbee switches arrive via Zigbee2MQTT
inside HA, and HA bridges the Alexa household.

1. HA → click your user (bottom left) → **Security** tab
2. **Long-Lived Access Tokens** → Create Token
3. Set `HOMEASSISTANT_URL` and `HOMEASSISTANT_TOKEN`

Home tab tiles will show live entity state over the websocket API and toggle
`light`, `switch`, `climate`, `cover`, `scene` and `script` entities, grouped by
HA area.

### Anthropic — *Phase 3*

Set `ANTHROPIC_API_KEY`. All extraction goes through a review queue; nothing is
written to a calendar without a person accepting it.

---

## Conventions

- **Touch first.** Every interactive element is at least 44×44px (`.touch-target`).
  No hover-only affordances, no double-taps.
- **Alpine for local state, Livewire for persistence.** If tapping something can
  be answered from data already on the page, it must not hit the server.
- Livewire 4 single-file components live in `resources/views/components/`, named
  `⚡name.blade.php`. `Route::livewire('/path', 'folder.name')` mounts one.
- The `List` / `ListItem` models from the brief are `Checklist` / `ChecklistItem`
  here — `list` is a reserved word in PHP and cannot be a class name.
- `Event` identity is `(calendar_id, external_id, recurrence_id)`, enforced through
  a `uid_hash` column because a 512-char `external_id` overflows MySQL's
  3072-byte index limit.

## Artisan commands

| Command | Purpose |
| ------- | ------- |
| `familyhub:seed-demo` | Seed the household from `.env` (`--fresh` wipes first, `--household-only` skips demo events) |
| `familyhub:display-token` | Show the wall display pairing URL (`--new` rotates the token) |
| `sync:calendars` | *Phase 2* |
| `capture:process` | *Phase 3* |
