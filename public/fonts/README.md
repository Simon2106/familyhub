# Fonts

## Caveat

`caveat-latin.woff2`, `caveat-latin-ext.woff2` — the Caveat variable font
(weight axis 400–700), latin and latin-ext subsets, taken from Google Fonts
(v23) and served from here rather than from fonts.gstatic.com.

Self-hosted for one reason: the wall is a Raspberry Pi in a kitchen, and it
has to draw the notes board correctly on a morning when the internet is down.
A webfont fetched from a CDN at paint time is a webfont that is sometimes not
there. These two files are also cached by the service worker, so the second
boot needs no network at all.

Licensed under the SIL Open Font License 1.1 — see `caveat-OFL.txt`, which
must travel with the files.

Only the notes board asks for this family, so it is only downloaded on a page
that draws a note.
