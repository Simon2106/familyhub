# FamilyHub

A family wall-calendar app for a single household — a self-hosted replacement for
Skylight / Hearth. It runs on an always-on wall-mounted iPad (Safari PWA in Guided
Access) and on family phones.

**Stack:** Laravel 13 · Livewire 4 · Alpine · Tailwind 4 · MySQL · Redis · Horizon

**Calendars:** Apple iCloud only, over CalDAV. Google is out of scope — see `BRIEF.md`.

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
| **2** | iCloud CalDAV sync, two-way | **Done** |
| **3** | Capture — inbound email, photo/PDF/URL, Claude extraction, review queue | **Done** |
| 4 | Kids — chores, routines, rewards | Not started |
| 5 | Meals & shopping | Not started |
| 6 | Home Assistant, weather, bins, AI assistant | Not started |

### What Phase 1 ships

- Household, Member, CalendarAccount, Calendar, Event, Checklist, ChecklistItem models
- `/display` — the wall display: opens on today as per-member agenda columns,
  with a "This week" tab showing a member-by-day grid, plus the week strip,
  upcoming rail, lists, idle photo screensaver and scheduled dark mode
- `/app` — phone view with a per-member filter
- `/admin` — household and member settings, display pairing
- `/login` — email + password, rate limited. No registration route.
- PWA: manifest, offline-shell service worker, iOS standalone meta, safe-area
  handling, rubber-band suppression

Day and tab switching on the wall are pure Alpine — **zero** server round trips.
Livewire re-renders on a 60-second poll to pick up edits made from phones.

### What Phase 2 ships

- CalDAV against iCloud with an app-specific password; **credentials are entered
  in `/admin` and stored encrypted**, never in `.env`
- **Multiple Apple IDs** can be connected side by side
- Discovery: principal → calendar-home → calendars, filtering out reminder lists
- `sync-collection` incremental sync with a `calendar-query` fallback, and
  automatic recovery when iCloud rejects a stale token
- Recurring events with `RECURRENCE-ID` overrides, all-day events, `DURATION`,
  cancellations and deletions
- Two-way: create, edit and delete from the phone, pushed with `If-Match` /
  `If-None-Match` so a concurrent edit is refused rather than clobbered
- Horizon-backed queues, a 5-minute poll and a nightly full pass
- `/admin/calendars`: per-account status, last sync, errors, force resync,
  per-calendar member assignment and visibility

### What Phase 3 ships

Things arrive, Claude reads them, a person decides. **Nothing reaches a calendar
until it is accepted.**

Entry points, all landing in the same queue and running the same job:

| | |
| --- | --- |
| **Inbound email** | Postmark posts to `/webhooks/postmark/<secret>`; body and readable attachments are stored |
| **Photo / PDF** | From `/app` — the camera, the photo library, or a file |
| **Pasted text** | From `/app` |
| **Link** | Fetched and reduced to readable text |
| **Share sheet** | The PWA declares a `share_target`, so anything can be shared to FamilyHub from iOS |

Extraction uses structured outputs (`output_config.format`) against a strict JSON
schema, so the response cannot come back as prose that needs hunting through. The
prompt is told today's date, when the material was received, the household's
timezone and its members' names, and is pushed to find *every* date, infer a
missing year from context, and lower its own confidence when it had to guess.

The review inbox is on the wall's **Review** tab and at `/app/review`. Each item
shows what it is, when, who it seems to be about, and how sure the model was.
Accept writes an event through the Phase 2 iCloud write-back, or creates a to-do
for a task. **Accept all confident** takes only the items scoring 80+. Phones can
correct an item before accepting; the wall accepts or rejects.

Some deliberate behaviour:

- An **undated event becomes a to-do** rather than being refused — inventing a
  date would be worse, and refusing loses the item.
- **iPhone HEIC photos are converted** to JPEG before sending, because the
  Messages API does not read HEIC. Large photos are downscaled first.
- A capture that finds **nothing is finished, not failed**.
- A **duplicate delivery** does not double the items.
- Failures keep their reason and can be retried from the inbox.

```sh
php artisan capture:process              # queue anything waiting
php artisan capture:process --now        # run inline
php artisan capture:process --retry-failed
```

### Keeping the wall up to date

The wall iPad is never reloaded by hand, so it updates itself.

- `/version` returns the deployed build id — the hash of the Vite manifest,
  falling back to the git SHA, then to a file mtime. It is unauthenticated and
  never cached, because the display must read it before Livewire boots and it
  reveals nothing beyond "the app was redeployed".
- The page embeds that id in a `<meta name="build-version">` and polls the
  endpoint every 60s. When it changes, the display reloads — **but only after
  30 seconds with no touch**, so it never reloads under someone's finger. A
  reload found while the screen is in use is deferred to the next quiet moment.
- Belt and braces: a reload when the daily time (03:45) is crossed, and a check
  whenever the page returns from background.
- A dropped connection is never mistaken for a new version, and a page that has
  just started will not reload itself for a minute — that guards against a boot
  loop if the embedded id and the endpoint disagree mid-deploy.
- The service worker caches **only** content-hashed build assets and the offline
  card. HTML and Livewire traffic are never cached. A new worker calls
  `skipWaiting()` and `clients.claim()`, since a wall display has no second tab
  to close.
- The running build is shown under **About** in `/admin`, and on the wall as the
  title tooltip on the household name.

Nothing on the deploy side is required beyond building assets — the version is
derived from them, and is cached for only 60 seconds so detection does not
depend on remembering `cache:clear`.

### Household to-dos

The wall's home view carries a **To do** panel under "Coming up", in both the
today and This week views. To-dos are ordered overdue → due soonest → undated,
with an optional assignee shown as their colour dot.

- Tap to tick. The tick is optimistic in Alpine and persisted by Livewire; the
  item lingers a few seconds so the tick registers, then fades off the panel.
- Ticked items are findable under **Done** on the Lists tab, and can be
  un-ticked from there. They are kept for a configurable window (default 30
  days, set in `/admin`) and then removed by a nightly `familyhub:prune-done`.
  **Clear done** on the Done row empties it immediately.
- A **dated** to-do also appears in its member's column on that day, below the
  events and marked as a task. Undated ones live only in the panel — pinning them
  to an arbitrary day would be a lie. To-dos with no member go to the Household
  column, which appears on any day that needs it.
- The **+** on the panel opens a large-text quick add (title, optional due date,
  optional member) as a centred dialog. It is pinned to the *visual* viewport,
  so it clears the wall's tab bar and rides above the iOS on-screen keyboard
  rather than hiding behind it.
- On phones (`/app`) the same panel is editable — each row gets an edit control
  for changing the title, date, assignee, or deleting.

There is no separate to-do model. The household to-do list is an ordinary
`Checklist` with `is_home_list` set, created on demand by `Checklist::home()`.

### Member attribution

Events are matched to family members by reading their titles and locations.

- Members have **name aliases** — Simon also answers to "SW". First names are
  seeded automatically; the member's own name always matches.
- **Places** (`/admin/places`) are the schools, workplaces and clubs that turn
  up in titles, each with its own aliases and a type. A place is attached to the
  members it concerns, and each attachment has an **include automatically**
  toggle: "Ice" is attached to both Simon and Jenna with Jenna's toggle off, so
  Ice events go to Simon unless Jenna is also named.
- Matching is case-insensitive and whole-word, so "Jo" does not match "Jones",
  and multi-word phrases like "Ice and a Slice" match as phrases.
- **Names win outright.** If a title names anybody, the event goes to exactly
  those people and places are not consulted — naming someone is an explicit
  statement about who the event is for, while a place is only a default for when
  nobody said:

  | Title | Goes to | Why |
  | --- | --- | --- |
  | `JW Ice WFH` | Jenna | named, so Ice stays out |
  | `Ice offsite` | Simon | nobody named, so Ice speaks for its automatic member |
  | `SW JW Ice party` | Simon, Jenna | both named |

- Events belong to **many** members: "SW + JW dentist" appears in both columns.
- Assigning members by hand in the event editor **pins** them — later syncs
  leave that event alone until you hand it back with "Match from the title
  instead".
- No match falls back to the calendar's owner, then to the household, which
  gets its own column on days that need one.

Attribution runs on every sync and every save. After changing aliases or places
it re-runs automatically, and there is a **Re-run attribution** button on the
Calendars page.

After a deploy that changes the matching rules, re-run it over existing events:

```sh
php artisan familyhub:attribute --dry-run   # shows what would move
php artisan familyhub:attribute
```

Both leave hand-assigned events alone, and the plain run reports how many it
skipped for that reason.

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

Open the printed URL on the iPad. The token is exchanged for a year-long cookie
and the display renders in the same response — no redirect — with the token left
in the URL.

**The token stays in the URL on purpose.** iOS gives a home-screen web app its
own cookie jar *and* its own `localStorage`, both separate from Safari's, so a
pairing done in Safari does not carry into the installed PWA. Leaving the token
in the URL means "Add to Home Screen" captures it, and the PWA re-pairs itself
inside its own storage on first launch. The display also serves its own manifest
whose `start_url` carries the token, so every relaunch re-pairs.

The page mirrors the token into `localStorage`, so a context that later loses
its cookie re-pairs itself without anyone reaching up to the wall. That recovery
is per-context: Safari recovers Safari, the PWA recovers the PWA.

`php artisan familyhub:display-token --new` rotates the token. Any paired iPad
will need re-pairing.

**Troubleshooting:** the middleware only ever answers 200 (paired), 403 (wrong
or missing token) or 503 (no token configured, or no household seeded). It never
returns 404 and no longer redirects. So a 404 from `/display?token=...` means the token was
*accepted* and something behind it failed — almost always a database that was
migrated but never seeded. Run `php artisan familyhub:seed-demo --household-only`.

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

Raise the upload limits before pointing Postmark at the server — inbound email
with attachments is up to 35MB and returns 413 otherwise:

```nginx
client_max_body_size 40M;   # nginx server block
```
```ini
post_max_size = 40M         # php.ini
upload_max_filesize = 40M
```

Seed the household on first deploy, or `/display` will pair and then fail:

```sh
php artisan migrate --force
php artisan familyhub:seed-demo --household-only
```

The app trusts proxy headers (`trustProxies(at: '*')`) because Forge terminates
TLS at nginx and forwards plain HTTP. Without it the pairing redirect downgrades
to `http://` and the Secure pairing cookie is dropped. Only trust `*` while the
app is reachable solely through its own nginx, which is the standard Forge
droplet layout.

Calendar syncing needs Horizon and the scheduler running under supervisor:

```sh
php artisan horizon        # queue supervisor (Forge: a daemon)
* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
```

Horizon's dashboard is at `/horizon`, gated on being signed in. Laravel's
generated gate ships an empty allowlist that locks everyone out in production;
`HorizonServiceProvider::gate()` replaces it, which is safe here because there is
no public signup and every login is a parent.

---

## Integration setup

These cover later phases. The `.env` keys already exist and are documented in
`.env.example`.

### iCloud CalDAV

1. [appleid.apple.com](https://appleid.apple.com) → Sign-In and Security
2. **App-Specific Passwords** → generate one, label it "FamilyHub"
3. In FamilyHub, go to **Settings → Calendars → Add an iCloud account** and
   enter the Apple ID with that password — never the real Apple ID password
4. Repeat for each family Apple ID you want to sync

Credentials are verified against iCloud before anything is written to the
database, then stored encrypted (`CalendarAccount::$credentials` is an
`encrypted:array` cast) and never rendered back or exposed in JSON.

**There is nothing to put in `.env`.** The only iCloud settings there are
transport-level (`ICLOUD_CALDAV_URL`, timeouts, sync window).

Google Calendar is deliberately not implemented; see the amendments in `BRIEF.md`.

#### How syncing runs

| | |
| --- | --- |
| Every 5 minutes | `sync:calendars` — incremental, using each calendar's sync token |
| Nightly at 03:30 | `sync:calendars --force` — full pass, catches anything a token missed |
| Nightly at 04:00 | `familyhub:prune-done` — removes completed to-dos past retention |
| On demand | **Sync now** / **Force resync** per account in `/admin/calendars` |

Jobs run on the `sync` queue, which Horizon prioritises over `default`. Both jobs
use `WithoutOverlapping`, so a slow sync cannot race itself on sync tokens.

iCloud offers no push channel we can subscribe to, so polling is the only option.
`sync-collection` keeps each poll cheap: the server returns only what changed.

#### Sync window

A full pass asks iCloud for `CALDAV_WINDOW_BACK` days of history and
`CALDAV_WINDOW_FORWARD` days ahead (90 / 400 by default). Events outside that
window are never requested — and, importantly, are never deleted locally for
being absent from a response that never covered them.

### Postmark inbound email

> **Raise the upload limits first, or inbound email with attachments returns
> 413 and Postmark keeps retrying it.** Postmark posts the whole email —
> attachments base64-encoded inside the JSON body — and allows up to **35MB**.
> Base64 inflates by a third, so the request can approach 40MB.
>
> **nginx** (Forge: Sites → your site → Edit Nginx Configuration), in `server {}`:
>
> ```nginx
> client_max_body_size 40M;
> ```
>
> **PHP** (Forge: Server → PHP → or edit `/etc/php/8.3/fpm/php.ini`):
>
> ```ini
> post_max_size = 40M
> upload_max_filesize = 40M
> ```
>
> Reload both afterwards: `sudo service nginx reload && sudo service php8.3-fpm reload`.
>
> `upload_max_filesize` matters for the phone upload and share-target paths;
> `post_max_size` and nginx's limit are what inbound email hits. PHP silently
> discards a POST body over `post_max_size` — the request arrives with empty
> input rather than an error — so set both.
>
> The same applies in development: a stock PHP has `post_max_size = 2M`.

1. Postmark → your server → **Inbound** stream
2. Generate a secret: `php -r 'echo bin2hex(random_bytes(24));'` → `POSTMARK_INBOUND_SECRET`
3. Set the inbound webhook to
   `https://hub.yourdomain.com/webhooks/postmark/<that secret>`
   (Postmark signs nothing, so the secret in the path is the authentication.
   Basic auth with the same value as the password works too.)
4. Set `FAMILYHUB_INBOUND_ADDRESS` to the address the household forwards to,
   e.g. `ai@hub.yourdomain.com`
5. Forward school newsletters to that address

#### Who is allowed to send

An inbound stream receives everything sent to the domain, so without a filter a
stray or spam message becomes a capture. Mail is only processed when
`FAMILYHUB_INBOUND_ADDRESS` appears among its recipients — checked across
`ToFull`, `CcFull`, `BccFull` and the raw `To`/`Cc`/`Bcc` headers, **and** the
envelope `OriginalRecipient`, because a message auto-forwarded by a rule still
carries the school's address in `To` and only the envelope says where it landed.

Matching ignores case, and a `+tag` still reaches the address — `ai+sandygate@…`
counts as `ai@…`, so a source can be tagged without configuring another mailbox.

Anything addressed elsewhere is answered **200** and logged with what it was
addressed to. A non-2xx would have Postmark retrying a message that is never
going to be wanted. The check runs before anything is stored, so an unwanted
message never writes attachments to disk.

Leaving `FAMILYHUB_INBOUND_ADDRESS` blank accepts everything the stream receives,
which is how this behaved before the setting existed.

A wrong or missing secret returns **404**, not 401 — an unauthenticated caller
learns nothing about whether the endpoint exists. An empty or unreadable email is
answered 200 and ignored, because a non-2xx would make Postmark retry it forever.

#### Attachment size, end to end

| Limit | Value | Set where |
| --- | --- | --- |
| Postmark's own ceiling | 35MB | Postmark |
| nginx request body | 40M | `client_max_body_size` |
| PHP request body | 40M | `post_max_size` |
| Accepted per attachment | 35MB | `PostmarkInboundController::MAX_ATTACHMENT_BYTES` |
| Sent to Claude, per attachment | 12MB | `AttachmentPreparer::MAX_BYTES` |
| Sent to Claude, per request | 20MB | `AttachmentPreparer::MAX_TOTAL_BYTES` |

The last two are lower on purpose: the Messages API caps a request at 32MB and
base64 costs a third on top, so several attachments share one budget. Anything
that will not fit is **stored and named** rather than dropped — the review inbox
says which files went unread, and a capture whose only attachment was unreadable
fails with that reason instead of reporting "nothing found", which would look
exactly like an email that had no dates in it.

Photos are downscaled to a 2000px long edge before sending, so a 12MP phone
photo of a letter is nowhere near these limits.

### Anthropic

`ANTHROPIC_API_KEY` is required for capture; without it captures are stored but
never read.

`ANTHROPIC_MODEL` defaults to **`claude-sonnet-5`**. Swap in `claude-opus-5` if a
particular school's newsletters prove hard to read, or `claude-haiku-4-5` to cut
cost at some accuracy.

Current models think adaptively by default, and that spend comes out of the same
`ANTHROPIC_MAX_TOKENS` budget as the answer — so a very long term calendar can be
cut off mid-answer. That is reported as "the answer ran past the token budget"
with the current value, rather than as a confusing JSON error. Raise
`ANTHROPIC_MAX_TOKENS` (default 16000) if you see it. Sampling parameters are not
sent: current models reject `temperature` and `top_p` outright.

### Home Assistant — *Phase 6*

HA is the **only** smart-home driver. Zigbee switches arrive via Zigbee2MQTT
inside HA, and HA bridges the Alexa household.

1. HA → click your user (bottom left) → **Security** tab
2. **Long-Lived Access Tokens** → Create Token
3. Set `HOMEASSISTANT_URL` and `HOMEASSISTANT_TOKEN`

Home tab tiles will show live entity state over the websocket API and toggle
`light`, `switch`, `climate`, `cover`, `scene` and `script` entities, grouped by
HA area.

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
  3072-byte index limit. One `.ics` resource can hold a recurring master *and*
  its modified occurrences, which all share a UID — hence `recurrence_id`.
- CalDAV code lives in `app/Services/CalDav/`. `CalDavClient` is pure transport
  and knows nothing about our models, so it can be faked wholesale in tests
  (see `tests/Support/FakeICloud.php`).

## Artisan commands

| Command | Purpose |
| ------- | ------- |
| `familyhub:seed-demo` | Seed the household from `.env` (`--fresh` wipes first, `--household-only` skips demo events) |
| `familyhub:display-token` | Show the wall display pairing URL (`--new` rotates the token) |
| `sync:calendars` | Sync connected iCloud accounts (`--force` full pass, `--account=` one account, `--now` inline) |
| `familyhub:attribute` | Re-run member attribution over existing events (`--dry-run` to preview) |
| `familyhub:prune-done` | Delete completed to-dos past the retention window (`--dry-run` to preview) |
| `capture:process` | Extract items from waiting captures (`--now` inline, `--retry-failed`, `--capture=`) |
