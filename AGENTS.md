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
  location. **Names win outright**: if any name alias matches, places are not
  consulted at all. `AttributionMatcher` is compiled once per household and
  reused — never build one per event. Events with `attribution = 'manual'` are off
  limits: a person chose those by hand.
- **Capture (Phase 3) never writes to a calendar directly.** Everything lands in
  `captures`/`capture_items` and waits for a person; `ItemAcceptor` is the only
  path to an Event, and it goes through the Phase 2 write-back. Swap
  `ItemExtractor` for `Tests\Support\FakeItemExtractor` to test the pipeline
  without calling the API.
- **Structured outputs accept only a subset of JSON Schema.** No `minimum`,
  `maximum`, `minLength`, `maxLength`, `pattern` or `maxItems`; nullability is
  `anyOf: [{type:...},{type:'null'}]`, never a `["string","null"]` union; every
  object needs `additionalProperties: false`. An unsupported keyword is a 400 at
  request time, one keyword per attempt. `SchemaSupportTest` guards this — state
  ranges in the description and enforce them in `ExtractionParser`.
- **`WithoutOverlapping` releases by default** (`$releaseAfter` is `0`, not
  `null`), which burns an attempt every time the lock is held and ends in
  `MaxAttemptsExceededException`. Every job here uses `->dontRelease()`.
- **A job's status guard must not swallow the queue's own retries.** A worker
  calls `failed()` only after the last attempt, so a capture sits on
  'processing' in between; skipping that status made retries return early —
  which counts as success, so `failed()` never ran. Note that `dispatch_sync`
  calls `failed()` on every exception, so it hides this: test `handle()` directly.
- **Inbound email is filtered by recipient** (`FAMILYHUB_INBOUND_ADDRESS`).
  Anything addressed elsewhere gets a 200 and a log line — never a non-2xx,
  which would have Postmark retrying it forever. Match on the envelope
  `OriginalRecipient` as well as the headers: forwarded mail keeps the original
  `To`.
- **Inbound email needs nginx `client_max_body_size` and PHP `post_max_size` at
  40M** — Postmark posts attachments base64-encoded inside the JSON body, up to
  35MB. Attachment limits are layered (see README § Attachment size, end to end);
  anything too large to send is stored and named, never silently dropped.
- **A Livewire method must not share a name with a public property** — the
  property shadows it client-side and `$wire.name()` silently does nothing.
  `tests/Feature/Capture/ComponentNamingTest.php` guards this.
- **The wall self-updates.** `/version` exposes the build id, the page embeds it,
  and `resources/js/updater.js` polls and reloads — only when idle. Keep that
  module free of DOM and timers so it stays testable; it is covered by
  `tests/js/updater.test.mjs`. The service worker must never cache HTML or
  Livewire traffic.
- **Household to-dos are a `Checklist` with `is_home_list`**, not a separate
  model — use `Checklist::home()`. Ticked to-dos deliberately linger in the
  panel query for a few seconds so they can fade rather than vanish mid-tap.
- **A dated to-do is hidden until its surface date** (`scopeSurfaced` /
  `isSurfaced()`), so any new to-do list must filter by it or it will show work
  that is weeks away. Pass the lead days in when filtering a loaded collection —
  `isSurfaced()` otherwise loads the checklist and household once per item.
- **Date columns use `App\Casts\CalendarDate`, not Eloquent's `date` cast.**
  The built-in cast writes `Y-m-d H:i:s`; MySQL's DATE column truncates it and
  SQLite does not, so `due_on <= '2026-07-15'` is true in production and false
  in the test suite. Same class of trap as `App\Casts\UtcDateTime`.
- **Compare due dates as dates.** Household midnight is 23:00 UTC the previous
  day for half the year, so subtracting a UTC-parsed date column from it is off
  by one. `ChecklistItem::daysUntilDue()` reduces both sides to `Y-m-d` first.
- **Never name a model method after one of its columns.** Eloquent resolves a
  missing attribute by checking whether a method of that name exists and calling
  it to see if it returns a relationship, so `section()` reading a `section`
  column recurses until the process runs out of memory — with a stack trace in
  HasAttributes, nowhere near the model. It only misbehaves when the attribute
  is absent from the instance, which is the state of a row created without that
  column, so it hides from the obvious tests. `ModelNamingTest` checks every
  model by reflection.
- **Long-running commands must watch for deploys.** `App\Support\DeployWatch`
  compares the build id the process started on against the current one; a daemon
  that does not do this keeps running the code it booted with forever. Exit 0
  when it changes and let the process manager restart it — and never write a
  deploy hook that restarts daemons by name.
- **Nothing is written by rendering.** Chores, routines and meal plans all
  project from a rule onto a date and only create a row when something is
  ticked. Adding a nightly sweep would undo that: a missed run then means
  missing state, and looking at last month would backfill it.
- **The points ledger is append-only and settles to a target**, never `+= n`.
  Use `PointsLedger`; do not write `PointEntry` rows directly, or double
  payments and un-reversible deductions come straight back.
- **A model with column defaults needs `protected $attributes` too.** A freshly
  created `Chore` had `null` for `is_active` in memory — the database default
  applies on insert but is never read back — so `occursOn()` answered "no" for
  every day until something reloaded it.
- **Livewire owns some names on the component, and shadowing one fails
  silently.** A `#[Computed] slots()` is swallowed by Livewire 4's own slots
  feature: the property reads as an empty collection, every write guarded on it
  no-ops, and nothing throws. `ComponentNamingTest` checks a deny-list as well
  as property/method collisions within the component. Add new components to its
  data provider.
- Livewire `#[Computed]` only caches on **property** access (`$this->days`).
  Calling `$this->days()` re-runs the method, which is an easy way to
  reintroduce an N+1 in the wall display.
- CalDAV lives in `app/Services/CalDav/`. `CalDavClient` is transport only —
  keep model knowledge out of it. Fake iCloud with `tests/Support/FakeICloud.php`;
  note that `Http::fake()` MERGES stubs rather than replacing them, so that
  helper drives everything from statics read at request time.

Run `php artisan test` and `npm test` before calling anything done.
