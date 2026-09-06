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
- Phase 6 ships **Home Assistant only** — the VoiceMonkey driver in the original
  brief was dropped.

Run `php artisan test` and `npm test` before calling anything done.
