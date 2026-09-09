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
- **The suite runs SQLite; production runs MySQL.** Anything expressed as raw
  SQL has to work in both, and a green suite is not proof that it does. The one
  that bit: `LIKE … ESCAPE '\'` is fine in SQLite and a syntax error in MySQL,
  which treats a lone backslash in a string literal as an escape itself — every
  search would have 500'd in production. `HouseholdSearch::ESCAPE` is `!` for
  that reason. When you write `whereRaw`, check it against the real database
  with `php artisan tinker` before believing the tests.
- **Date columns use `App\Casts\CalendarDate`, not Eloquent's `date` cast.**
  The built-in cast writes `Y-m-d H:i:s`; MySQL's DATE column truncates it and
  SQLite does not, so `due_on <= '2026-07-15'` is true in production and false
  in the test suite. Same class of trap as `App\Casts\UtcDateTime`.
- **Compare due dates as dates.** Household midnight is 23:00 UTC the previous
  day for half the year, so subtracting a UTC-parsed date column from it is off
  by one. `ChecklistItem::daysUntilDue()` reduces both sides to `Y-m-d` first.
- **Every dialog is `<x-modal>`.** Two bugs came out of hand-rolling them, both
  invisible until someone used a device. The dim and the centring must be the
  same element — as two, the full-screen centring layer covers the backdrop and
  "tap outside" silently does nothing. And a dialog must be measured against the
  **visual** viewport (`--vv-top` / `--vv-height`), not the layout one: on iOS
  the keyboard shrinks the visual viewport and leaves the layout viewport at
  full height, so `fixed ... bottom-0` puts the Save button behind the keyboard.
  `ModalDismissalTest` fails any view that builds its own.
- **Never name a model method after one of its columns.** Eloquent resolves a
  missing attribute by checking whether a method of that name exists and calling
  it to see if it returns a relationship, so `section()` reading a `section`
  column recurses until the process runs out of memory — with a stack trace in
  HasAttributes, nowhere near the model. It only misbehaves when the attribute
  is absent from the instance, which is the state of a row created without that
  column, so it hides from the obvious tests. `ModelNamingTest` checks every
  model by reflection.
## Wall kiosk

The wall display runs on a Raspberry Pi 5 (Bookworm, Wayland) driving a 15.6"
4K touchscreen through Chromium in kiosk mode.

**URL.** `https://hub.thewills.uk/display?token=1bbf8365b5429ac252bc9fd5e269dc5a87b51f23453809e4` — get the token
with `php artisan familyhub:display-token`. The token stays in the URL on
purpose (see `EnsureDisplayToken`); the middleware sets a year-long cookie and
renders in the same response, so the kiosk survives a cookie wipe by reloading
its own start URL.

**Chromium flags the app is built around:**

```sh
chromium-browser \
  --kiosk \
  --force-device-scale-factor=2 \
  --use-fake-ui-for-media-stream \
  --noerrdialogs --disable-infobars --disable-session-crashed-bubble \
  --check-for-update-interval=31536000 \
  --app="https://hub.thewills.uk/display?token=<token>"
```

`--use-fake-ui-for-media-stream` auto-grants the microphone. Despite the name
it does not fake the audio — it answers the permission prompt, which is
otherwise a modal dialog on a screen with no keyboard and no way to dismiss it,
sitting in front of the calendar until somebody carries a keyboard to the wall.
A **USB microphone is required**: neither the Pi nor the EVICIV panel has one.

`--force-device-scale-factor=2` is the load-bearing one: it turns the 3840×2160
panel into **1920×1080 CSS pixels**, which is the only size the display layout
is designed and tested against. Change it and the layout is untested.

**What the app assumes about the environment:**

- **1920×1080 CSS px, landscape.** Every tab fits without scrolling at exactly
  that size; there is a Playwright check for it. Nothing is designed to scroll
  the page itself — panes scroll internally.
- **A microphone, if one is plugged in.** The header mic button appears only
  when `OPENAI_API_KEY` is set — a kiosk with no keyboard is the worst place to
  discover a missing setting. Recording stops on ~1.5s of quiet or at 15s
  (`resources/js/listen.js`, where the deciding is kept testable); the audio is
  posted, transcribed by Whisper in a queued job, answered by the same
  read-only assistant `/app` uses, and read back by OpenAI TTS unless muted in
  /admin. Nothing is written to the database — a question lives in the cache
  for ten minutes and the recording is deleted the moment it becomes words.
- **An on-screen keyboard, because Chromium on the Pi has none.** Every text
  field on the wall would otherwise be one nobody standing at it can fill in.
  It is `[data-kiosk]`-gated — but so is the iPad PWA, which has a keyboard of
  its own, so the attribute alone cannot tell them apart and user-agent
  sniffing is out. The first focus of a page load waits 350ms and asks the
  device a question it can only answer honestly: a real soft keyboard shrinks
  the visual viewport. Override it with `?keys=on` / `?keys=off` on the display
  URL, remembered in localStorage. Showing it sets `--vv-height` — the same
  variable `<x-modal>` already measures against — so every dialog in the app
  rises above it without knowing it exists. Logic in `resources/js/keyboard.js`,
  tested in `tests/js/keyboard.test.mjs`.
- **No emoji. Anywhere the wall draws.** The Pi has no emoji font, so an emoji
  on /display is an empty box, and every device that does have one draws them
  differently — the same forecast looked like three different forecasts on the
  wall, an iPad and a phone. Use `<x-icon name="…">`
  (`resources/views/components/icon.blade.php`); add a drawing there rather
  than reaching for a glyph. `NoEmojiOnTheWallTest` fails any wall view that
  gets one back, and checks every name the app asks for has something to draw —
  an unknown name falls through to a plain circle, which is a bug that looks
  like a design decision. Icons the household typed themselves (a chore's, a
  routine step's) are their data and are left alone; only the fallbacks around
  them are drawn.
- **Touch only, no cursor, no hover.** There are no hover-only affordances and
  there must not be: on this screen they are invisible. `[data-kiosk]` sets
  `cursor: none`, kills text selection outside inputs, and suppresses the
  long-press context menu — a menu nobody can dismiss without a keyboard.
- **No keyboard.** Anything that needs typing has to work with the on-screen
  keyboard, which is why every dialog is `<x-modal>` (visual-viewport aware).
- **The panel may only run at 4K30.** No behaviour may depend on a frame rate
  or on an animation completing: every animation here is decorative, and state
  changes land immediately regardless.
- **The monitor cannot be power-cycled remotely.** "Screen off" is therefore a
  full-black in-page layer (`.screen-off`), scheduled in /admin, evaluated in
  the household's timezone rather than the Pi's clock, waking on touch and
  settling back after two minutes. The tap that wakes it is swallowed. It is
  deliberately plain DOM and runs even if Livewire never boots — a wall that
  stays lit all night because a websocket failed is a wall somebody unplugs.
- **Self-update is polling, not push.** `resources/js/updater.js` fetches
  `/version` every minute and hard-reloads once the screen has been idle for
  30 seconds; there is a daily reload at 03:45 as a backstop. It needs no
  service worker and works the same in Chromium as in an iOS PWA. The service
  worker is an optional extra trigger, never the mechanism.
- **iPad PWA support is still live** and must stay: the same `/display` URL,
  the same layout, a different manifest. Do not tie kiosk behaviour to user
  agent — it keys on `data-kiosk`, which the display layout always sets.

- **`familyhub:ha-listen` runs as a Forge daemon.** Exact settings, for
  copying into Forge → Server → Daemons:

  | Field | Value |
  | --- | --- |
  | Command | `php8.4 /home/forge/hub.thewills.uk/current/artisan familyhub:ha-listen` |
  | Directory | `/home/forge/hub.thewills.uk/current` |
  | User | `forge` |
  | Processes | `1` |
  | Start seconds | `1` |
  | Stop seconds | `10` |
  | Stop signal | `SIGTERM` |
  | Restart | `autorestart=true` (Forge's default) |

  **`current/`, not the site root.** Zero-downtime deploys serve from
  `releases/<timestamp>` with a `current` symlink; a daemon pointed at the site
  root would run whatever `artisan` happens to sit there, which is not the
  deployed code. Match the PHP binary and path to the existing Horizon daemon.

  One process, always: two listeners would both write the state cache and
  double every broadcast. `autorestart=true` matters because the command exits
  **0** when it sees new code — a manager set to restart only on unexpected
  codes would read that as the work being finished and leave it down. No deploy
  hook is needed; it notices the build id change within a minute and restarts
  itself onto the new code. It also stops cleanly on SIGTERM, so `forge daemon:restart`
  and a server reboot are both graceful.
- **The build id must be read through the deploy symlink, not `base_path()`.**
  PHP resolves symlinks in `__DIR__`, so inside a daemon started via
  `current/artisan` the base path is pinned to the release it started in. Read
  the manifest from there and a long-running process reads its own copy for
  ever and never notices a deploy — the exact thing `DeployWatch` exists to
  notice. `BuildVersion::livePath()` finds the `current` symlink beside the
  release, overridable with `FAMILYHUB_LIVE_PATH`, and `DeployWatch` calls
  `clearstatcache(true)` so the realpath cache cannot pin it either.
- **Long-running commands must watch for deploys.** `App\Support\DeployWatch`
  compares the build id the process started on against the current one; a daemon
  that does not do this keeps running the code it booted with forever. Exit 0
  when it changes and let the process manager restart it — and never write a
  deploy hook that restarts daemons by name.
- **The assistant's read-only promise is the tool surface, not the prompt.**
  `AssistantTools` is what the model can reach, and every method behind it is a
  read. Adding a tool that writes would break a promise the page makes in
  words, so do not — and `HouseholdFactsTest` runs every reader and asserts the
  database is byte-for-byte unchanged. Household context comes from
  `HouseholdBrief`, shared with the capture pipeline: two descriptions that
  drift are two families as far as a model is concerned.
- **Media players are found, not configured.** They are deliberately outside
  `HomeAssistant::DOMAINS` (which is what the tile picker offers), and read from
  the same `/api/states` reading the tiles use — resolve `HomeAssistant` once
  per render, because the shared cache may be off entirely. Controls are an
  allow-list of actions; never pass a service name through from the browser.
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
