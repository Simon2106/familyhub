# Working on FamilyHub

Read `README.md` first — it carries the setup, the phase plan and the
conventions. The points most easily got wrong:

- **Stack is Laravel 13 + Livewire 4 + Tailwind 4**, not the Laravel 11 +
  Livewire 3 + Breeze named in the original brief. Breeze is deliberately not
  installed. Auth is login-only; there is no registration route.
- **Livewire 4 single-file components** live at
  `resources/views/components/<folder>/⚡<name>.blade.php` and are routed with
  `Route::livewire('/path', 'folder.name')`.
- **Times are stored in UTC and displayed in `FAMILYHUB_TIMEZONE`.** Use
  `Household::todayLocal()` / `nowLocal()` for day boundaries. Never swap
  `App\Casts\UtcDateTime` for Eloquent's `datetime` cast — that one discards the
  incoming zone.
- **Alpine owns local state, Livewire owns persistence.** Anything answerable
  from data already on the page must not hit the server. Day and tab switching
  on the wall display currently cost zero round trips; keep it that way.
- **Touch targets are 44px minimum** (`.touch-target`). No hover-only UI.
- **The display pairing token stays in the URL and must not be stripped.** iOS
  scopes cookies and localStorage per context, so an installed PWA cannot
  inherit Safari's pairing — the token in `start_url` is what re-pairs it.
- Livewire 4's update endpoint is `/livewire-<hash>/update`, not `/livewire/`.
  Match it by pattern when filtering requests or writing service-worker rules.
- Phase 6 ships **Home Assistant only** — the VoiceMonkey driver in the original
  brief was dropped.
- **Calendars are iCloud-only** (CalDAV). Google is out of scope. iCloud
  credentials are entered in `/admin`, never `.env`, and several Apple IDs can be
  connected at once. See `BRIEF.md` for the full amended brief.
- **Member attribution** (`app/Services/Attribution/`) decides whose column an
  event lands in, by matching names, aliases and places against the title and
  location. `AttributionMatcher` is compiled once per household and reused —
  never build one per event. Events with `attribution = 'manual'` are off
  limits: a person chose those by hand.
- Livewire `#[Computed]` only caches on **property** access (`$this->days`).
  Calling `$this->days()` re-runs the method, which is an easy way to
  reintroduce an N+1 in the wall display.
- CalDAV lives in `app/Services/CalDav/`. `CalDavClient` is transport only —
  keep model knowledge out of it. Fake iCloud with `tests/Support/FakeICloud.php`;
  note that `Http::fake()` MERGES stubs rather than replacing them, so that
  helper drives everything from statics read at request time.

Run `php artisan test` and `npm test` before calling anything done.
