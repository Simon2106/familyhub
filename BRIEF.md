# FamilyHub — project brief

Personal family wall-calendar app (a self-hosted Skylight / Hearth replacement).

Single-household, personal use. Not multi-tenant, no billing, no public signup.
Runs on an always-on wall-mounted iPad (Safari PWA in Guided Access) plus family
phones (same PWA).

**Deploy target:** existing Forge/DigitalOcean droplet at `https://hub.yourdomain.com`.

**Stack:** Laravel 13, Livewire 4, Alpine, Tailwind 4, MySQL, Redis queues, Horizon.

**LLM:** Anthropic API (`claude-sonnet-4-6`) for all extraction/assistant work.

**Calendars:** Apple iCloud only, via CalDAV with an app-specific password.

Work in phases. Finish and demo each phase before starting the next. Ask before
adding packages beyond those listed.

---

## Amendments to the original brief

These supersede the text they replace. Newest first.

1. **Household to-dos on the wall's home view.**
   - A **"To do" panel under "Coming up"** on the right, in both the today and
     This week views: the household's open to-dos with an optional due date and
     an optional assigned member (shown as their colour dot). Sorted
     **overdue → due soonest → no date**.
   - Tap to tick, optimistic in Alpine and persisted through Livewire. Ticked
     items **fade out after a few seconds** and are then findable in a **"Done"**
     section on the Lists tab.
   - **Dated** to-dos also appear in that member's column on that day, below the
     calendar events and marked as a task so they read differently from an
     appointment. To-dos with no member appear in the Household column.
   - **Quick add** from the wall: a "+" on the panel opens a large-keyboard
     input for title, optional due date and optional member.
   - The same to-dos are **editable in `/app`** on phones.
   - Reuses the existing **Checklist** model: the household to-do list is a
     Checklist flagged `is_home_list`. No parallel model.

2. **Member attribution from event titles**, plus two wall-display changes.
   - The week strip runs Monday–Sunday with a **"This week"** segment to the
     left of Monday.
   - **The display opens on today** — the current day selected in the strip,
     showing today's events as per-member columns. "This week" is a tab the user
     taps, not the default.
   - Tapping a date shows only that day; tapping the same date again returns to
     today.
   - **"This week"** keeps "Coming up" on the right and turns the left panel into
     a grid: one column per member (plus Household only when the week has
     household events), one row per day Mon–Sun, each cell listing that member's
     events for that day as time + title. Today's row is highlighted and empty
     cells stay empty. Fits iPad landscape without horizontal scrolling at five
     columns; in portrait the rows stack, with the day as a section header and
     members as sub-groups. Tapping a day header selects that day.
   - Members have **name aliases** (Simon: "Simon", "SW") and **places** —
     named organisations with their own aliases and a type
     (school / work / club / other), e.g. Sienna → "Sandy Gate" (school,
     alias "SG"), Simon → "Ice" (work, aliases "Ice and a Slice", "IAAS").
   - A place can be attached to **several members**, each attachment carrying an
     **"include me automatically"** toggle. Ice is attached to both Simon and
     Jenna with Jenna's toggle off, so Ice events go to Simon by default and
     Jenna joins only when her own name or alias is in the title too.
   - Matching is case-insensitive, whole-word, and handles multi-word phrases,
     over the event **title and location**.
   - **Names win outright.** If the text matches one or more name aliases, the
     event goes to exactly those members and places are not consulted. A place's
     automatic inclusion applies only when no name alias matched. So
     "JW Ice WFH" → Jenna alone, "Ice offsite" → Simon by place default, and
     "SW JW Ice party" → both by name.
   - Events ↔ members is **many-to-many** ("SW + JW dentist" belongs to both). A
     **manual assignment in the event editor wins** and is never overwritten by a
     later sync. No match falls back to the calendar's owner, then to the
     household.
   - Attribution runs on sync and on save, with a **"re-run attribution"**
     action on the Calendars page and a `familyhub:attribute` command for
     deploys. Name aliases are seeded from first names.

3. **Calendars are iCloud-only.** Phase 2 was originally "Google (OAuth) + Apple
   iCloud (CalDAV)". Google is **out of scope** unless asked for later. Phase 2
   below is iCloud only.
4. **iCloud credentials are entered in `/admin`, not `.env`.** The app must
   support **multiple iCloud accounts**.
5. **Phase 6 ships the Home Assistant driver only.** The VoiceMonkey driver is
   dropped; see Phase 6.
6. **Stack is Laravel 13 + Livewire 4 + Tailwind 4**, not the Laravel 11 +
   Livewire 3 + Breeze originally specified. Laravel 11 left its security-fix
   window in March 2026 and this app is internet-facing. Breeze is not used — it
   hard-pins Livewire 3 and Tailwind 3 — so auth is a hand-rolled login-only
   surface, which is what the brief wanted anyway.

---

## Non-negotiables

- Every screen must be touch-first: 44px+ targets, no hover-only UI, no double-clicks.
- Wall display must feel instant: Alpine for local state (tick chore, swipe day),
  Livewire for persistence, `wire:navigate` for page changes, Livewire polling or
  Reverb for updates from phones.
- All LLM extraction goes through a REVIEW queue — nothing auto-commits to calendars.
- Dark mode auto by schedule (configurable, default 21:00–06:30).
- No external analytics/trackers.

---

## Phase 1 — Foundation + wall display ✅ done

1. Fresh Laravel + Livewire + Tailwind, email/password auth, no registration
   route; users seeded from `.env`.
2. Models: Household, Member (name, colour, avatar, is_child, pin),
   CalendarAccount (provider, credentials encrypted), Calendar (external id,
   name, member_id, colour), Event (external_id, calendar_id, title, start, end,
   all_day, location, notes, rrule, source_hash), Checklist, ChecklistItem.
   *(The brief's `List`/`ListItem` are `Checklist`/`ChecklistItem`: `list` is a
   reserved word in PHP.)*
3. Routes: `/display` (wall mode, token in URL, no auth prompt), `/app` (phone
   mode), `/admin` (settings).
4. Wall display layout (landscape and portrait, iPad 10.9"/11"):
   - Left: the week grouped by day, or one day as colour-coded member columns.
   - Right: week strip ("This week" + Mon–Sun) + upcoming list.
   - Bottom bar: Chores | Meals | Lists | Photos | Home tabs.
   - Idle → photo screensaver after N minutes; tap to wake.
5. PWA: manifest, service worker (offline shell only), `apple-mobile-web-app-capable`,
   `viewport-fit=cover`, disabled rubber-band scroll.

---

## Phase 2 — iCloud calendar sync (two-way)

**Google is out of scope.** iCloud only.

1. **CalDAV over HTTPS** to `caldav.icloud.com` using an Apple ID + app-specific
   password. Credentials are entered and managed in **`/admin`**, never in `.env`,
   and are stored encrypted at rest.
2. **Multiple iCloud accounts** must be supported — each family member may add
   their own Apple ID.
3. Discover principal → calendar-home → calendars via `PROPFIND`.
4. Sync with `sync-collection` `REPORT`, falling back to `calendar-query` by time
   range where the server does not advertise sync-collection support.
5. Parse and emit ICS with `sabre/vobject`.
6. Write-back on create/edit/delete from our UI, via `PUT` / `DELETE`, using
   ETags so a concurrent edit on the phone is not silently clobbered.
7. Handle: recurring events + exceptions (`RECURRENCE-ID`), timezone
   normalisation, deletions, duplicate detection by
   `(calendar_id, external_id, recurrence_id)`.
8. Sync runs on Horizon queues; the admin page shows last sync, errors, and a
   "force resync" button per account.

---

## Phase 3 — Capture (the Magic Import replacement)

1. Inbound email: Postmark inbound webhook → store raw + attachments (S3) → CaptureJob.
2. CaptureJob: send email body + any PDF/image attachments to Claude with a strict
   JSON schema:
   ```
   { items: [ { type: "event"|"task"|"note", title, start, end, all_day,
                location, notes, member_hint, confidence } ], summary }
   ```
   The prompt must extract ALL dates from newsletters/term calendars, infer the
   year from context, and flag uncertainty.
3. Review inbox UI (phone + wall): swipe to accept/reject, edit fields, assign
   member + calendar, "accept all high-confidence". Accepted → created via the
   Phase 2 write-back.
4. Additional capture entry points using the same job: photo upload from phone
   camera, PDF upload, paste text, PWA share-target (text/URL/files), URL fetcher.
5. WhatsApp: optional, via Twilio inbound webhook — stub only, behind a config flag.

---

## Phase 4 — Kids: chores, routines, rewards

1. Chore (title, member, recurrence rrule, points, requires_photo), ChoreInstance
   (due date, completed_at, approved_at).
2. Routine (morning/evening/bedtime, per member, ordered steps with icons); the
   wall display shows a big-tap checklist for the active routine window.
3. Rewards: points ledger per child, Reward catalogue (name, cost), redemption
   requests needing parent PIN approval. Optional allowance mode: points → £ at a
   configurable rate, weekly summary.
4. Kids' profile view on the wall: tap avatar → their day, their chores, their
   stars. PIN only needed for redemptions/edits.

---

## Phase 5 — Meals & shopping

1. Recipe (title, source_url, ingredients JSON, steps, servings, tags); import
   from URL via fetch + Claude extraction; import from a photo of a recipe.
2. MealPlan week grid (breakfast/lunch/dinner × 7), drag-and-drop on wall and phone.
3. "Generate shopping list" merges planned recipes' ingredients, dedupes by unit,
   pushes to a Shopping list. Phone view with tap-to-tick and optional export to
   Apple Reminders.

---

## Phase 6 — Smart home & integrations

*(Alexa household; Home Assistant on a Pi.)*

1. **Home Assistant driver only** (REST + websocket, long-lived token). Zigbee
   switches come in via Zigbee2MQTT in HA. Home tab tiles show live entity state
   and toggle devices; support `light`, `switch`, `climate`, `cover`, `scene`,
   `script` entity types. Group tiles by room from HA areas.
   *(The original VoiceMonkey webhook driver is dropped.)*
2. Weather tile (Open-Meteo, no key), bin collection (Buckinghamshire Council
   iCal/URL scrape), school term dates feed, now-playing via HA.
3. Household AI assistant: Livewire chat panel using Claude with tool-use over our
   DB (read calendar, add event, add list item, plan meals). Tools must call the
   same services the UI uses.
4. Alexa Skill (later, optional): custom skill with account linking to read
   today's agenda, chores, and dinner from our API.

---

## Ops

- `.env.example` fully documented.
- Artisan commands: `sync:calendars`, `capture:process`, `familyhub:seed-demo`.
- Horizon + scheduler in supervisor. Daily DB backup to S3. Sentry optional.
- README with: iPad setup (Add to Home Screen, Guided Access, auto-lock off,
  brightness schedule via Shortcuts), Postmark inbound setup, iCloud
  app-specific password steps, Home Assistant token setup.

## Definition of done per phase

- Works on iPad Safari PWA in both orientations.
- No console errors, no N+1.
- Feature tests for sync, capture extraction (mock LLM), chores recurrence,
  points ledger.
