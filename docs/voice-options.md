# Talking to FamilyHub without touching it

A written-up comparison of three ways to ask the household assistant a question
without walking up to the wall and tapping the microphone. **No code has been
written for any of this** — the point of the document is to decide which one is
worth building before building it.

Written September 2026. Prices and model names are the ones current then and
should be checked before anyone spends money on the strength of them.

---

## Where we are already

The tap-to-talk path on the wall exists and works. It is worth being precise
about it, because two of the three options below are ways of *starting* it
rather than replacing it:

1. Somebody taps the microphone on `/display`. Chromium on the Pi records with
   `MediaRecorder`; `resources/js/listen.js` watches the room's loudness and
   stops when the sentence ends (1.5s of quiet, 15s hard ceiling).
2. The audio is posted to `POST /display/listen`, behind the display token and
   throttled to 30/minute.
3. `AnswerSpokenQuestionJob` runs on the `capture` queue: transcribe with
   Whisper, ask Claude with the household's read-only tools, speak the answer
   with OpenAI TTS.
4. The wall polls `GET /display/listen/{id}` and plays the audio back.

So the assistant, its tools, the safety properties and the voice are all
built. What is missing is only this: **the wall has to be touched first.**

The gap that matters in practice is that somebody with their hands in the sink
cannot ask what time football is.

---

## Option A — a custom Alexa skill

Say "Alexa, ask FamilyHub what's for tea", and Alexa calls `hub.thewills.uk`.

### How it would work

A custom skill with a small interaction model — realistically one intent with
a free-text `AMAZON.SearchQuery` slot, so the family can ask anything the
assistant can already answer rather than a fixed list of phrasings. Amazon does
the wake word, the microphone and the speech recognition; we receive a JSON
request containing the transcript, run it through the existing
`ClaudeAssistant`, and return text for Alexa to speak.

The skill endpoint can be a route on the existing app. It cannot be behind the
display token — Amazon has to reach it — so it needs the standard skill request
verification: check the `SignatureCertChainUrl`, validate the certificate
chain, verify the signature over the raw body, and reject anything with a
timestamp more than 150 seconds old. That is a well-documented but genuinely
fiddly piece of work, and getting it wrong means an open endpoint to the
household's calendar.

Account linking would be needed to tie an Alexa user to this household. For a
single-household app the honest alternative is to skip OAuth entirely and pin
the skill to the one household, checking only that the request's
`application.applicationId` is ours. That is much less work and no less safe
here, but it does mean the skill can never be published beyond this account.

### Effort

**High.** Rough shape: skill manifest and interaction model, request signature
verification, a controller mapping the intent to the assistant, session
handling so "and the week after?" works, plus the Alexa developer console
setup. Two or three days of careful work, most of it in verification and in
the console rather than in the app.

### Cost

Nothing recurring from Amazon — custom skills are free to host on your own
endpoint. The per-question cost is what it already is: one Claude call with
tools, no Whisper (Amazon transcribes) and no TTS (Alexa speaks). That is
*cheaper per question* than the current wall path.

Hardware: needs an Echo in the kitchen. Around £25–£110 depending on model.

### Latency

Good. Amazon's recognition is fast and there is no Whisper round trip. The
budget is the constraint rather than the speed: **Alexa gives a skill roughly
8 seconds to respond.** The current assistant is a tool-using loop that may
make several lookups; it will sometimes exceed that. The mitigation is a
progressive response ("let me look") which buys ~30 seconds total, and that is
an extra thing to build and to get right.

### Privacy

The worst of the three. Every question is recorded and transcribed by Amazon,
associated with an Amazon account, and retained under their policy. A wall
calendar's questions are things like "when is Sienna's dentist" — that is a
child's medical appointment going to a third party that has no other reason to
know it. The answer we send back is also, briefly, Amazon's.

### Certification

Only needed to publish publicly. A private skill on the developer account can
be used on that account's own devices indefinitely without submission. Worth
knowing, because certification is the part that would otherwise dominate the
effort estimate.

---

## Option B — Home Assistant Assist with openWakeWord

Home Assistant is already integrated (`HA_URL`, `HA_TOKEN`, the switch board,
the media players). HA's own Assist pipeline does wake word, speech-to-text,
intent handling and speech-to-text back out, either on the Pi or on a dedicated
satellite.

### How it would work

Two sub-shapes, and they differ a lot:

**B1 — a Home Assistant Voice satellite (or an ESP32-S3 box)** in the kitchen.
Purpose-built hardware with a proper microphone array, on-device wake word,
and a mute switch. It talks to HA; HA runs the pipeline. To reach FamilyHub,
HA would need a custom conversation agent — an HA integration or a script — that
forwards the transcript to a FamilyHub endpoint and speaks the reply.

**B2 — openWakeWord on the Pi 5 itself**, using the Pi's existing microphone,
as a Wyoming satellite pointed at HA. Saves the hardware but competes for the
Pi's CPU with Chromium running a full-screen kiosk, and uses whatever
microphone the wall panel has, which is not designed for far-field pickup.

Either way FamilyHub needs one new thing: an endpoint HA can post a transcript
to and get an answer from. That is genuinely small — the assistant already
takes a string and returns a string.

### Effort

**Medium.** The FamilyHub half is small: one authenticated endpoint, one
config field. The HA half is the work — configuring the pipeline, wiring a
custom conversation agent, and choosing where speech-to-text runs. HA can use
local Whisper (slower on modest hardware) or a cloud STT. Expect a day or two,
much of it in HA rather than in this codebase, and much of that trial and error
with microphone placement and wake-word sensitivity.

### Cost

**B1:** an HA Voice Preview Edition is around £50–60; an ESP32-S3 box is £15–25.
**B2:** free, plus possibly a USB microphone (£15–30) if the panel's own is poor.

Per question: one Claude call. Local Whisper is free but wants CPU; a cloud STT
is a fraction of a penny. Either way, comparable to today.

### Latency

Depends entirely on where speech-to-text runs. Local Whisper on a Pi-class
machine is noticeably slow — several seconds for a sentence. A cloud STT is
about what the wall does today. Wake-word detection itself is fast and local.

### Privacy

**The best of the three.** The wake word never leaves the house. With local
STT, the audio never leaves the house either, and only the transcript reaches
FamilyHub — which is our own server. Even with cloud STT it is no worse than
the current tap-to-talk path, which already sends audio to Whisper. A
satellite with a hardware mute switch is a meaningful thing to be able to point
at when a child asks whether it is always listening.

### Hardware

B1 needs a device and somewhere to put it. B2 needs nothing new but leans on
the Pi. Note that a Pi 5 driving a 1080p kiosk has headroom, but openWakeWord
running continuously is a real, permanent CPU cost on a machine that also has
to keep a browser responsive.

---

## Option C — wake word in the Pi's own Chromium

Run a wake-word detector in the page that is already open on the wall, and have
it start the tap-to-talk flow that already exists. Porcupine's web build is the
practical choice; the browser's own `SpeechRecognition` is not, because
Chromium on desktop Linux does not implement it usefully.

### How it would work

Load the detector in `resources/js/listen.js`, keep an `AudioContext` open on
the microphone, and when the wake word fires, call the same `start()` the
microphone button calls today. Everything downstream — recording, silence
detection, upload, the job, the answer, the speaking — is unchanged.

Three things need care:

- **The microphone stays open forever.** Chromium will show a permanent
  recording indicator, and the kiosk's autoplay/permission policy has to be
  set so permission is granted once and never asked again.
- **The screensaver.** The wall dims and drifts a clock; the detector has to
  keep running through that and wake the screen when it fires.
- **False positives.** A kitchen with a radio in it will trigger a wake word
  occasionally, and each false trigger currently costs a Whisper call. The
  existing `patienceMs` guard already discards a recording where nobody spoke,
  which limits but does not eliminate this.

### Effort

**Low.** This is the only option that adds no new service, no new device and no
new endpoint. It is one library, one listener and a permission flag. A day,
including tuning.

### Cost

Porcupine is free for personal use with an access key; the free tier is
generous but is a per-user licence and does require an account. Per question,
identical to today. No hardware — the wall's microphone is already the one
being used.

### Latency

The best of the three for the wake word itself: detection is local and
immediate, and the pipeline it starts is the one already tuned. Total time to
answer is unchanged from tapping the button.

### Privacy

Good, and easy to explain: the wake word is detected on the Pi and nothing
leaves the house until somebody has actually said something. Audio then goes to
Whisper exactly as it does today, so this changes nothing about the existing
posture — it only removes the tap.

### Hardware

None, which is also the weakness: it only works within earshot of the wall
panel, whose microphone is not a far-field array. Standing at the sink might
work; the other end of the kitchen probably will not. **This is the thing to
test before building anything** — five minutes with the existing tap-to-talk
button from various points in the room will say whether the microphone is good
enough, and if it is not, option C is dead regardless of how cheap it is.

---

## Side by side

| | A — Alexa skill | B — HA Assist | C — Porcupine in Chromium |
|---|---|---|---|
| Effort | High (2–3 days) | Medium (1–2 days, mostly in HA) | Low (~1 day) |
| Hardware | Echo, £25–110 | £0–60 | none |
| Recurring cost | Claude only (cheapest per question) | Claude, plus STT if cloud | Claude + Whisper, as today |
| Latency | Fast, but an 8s response budget the tool loop may exceed | Slow with local STT, fine with cloud | Unchanged from today |
| Privacy | Poor — every question via Amazon | Best — wake word and optionally audio never leave the house | Good — wake word local, audio as today |
| Range | Wherever the Echo is | Wherever the satellite is | Within earshot of the wall only |
| New moving parts | Skill, signature verification, public endpoint | HA pipeline, custom conversation agent, one endpoint | One library |
| Risk if it fails | An open endpoint to the family's calendar | Fiddly, but contained | Wasted day |

---

## Recommendation

**Build C, and only after a five-minute test of the wall's microphone.**

The reasoning:

It is the only option that adds nothing to the system. No new device, no new
public endpoint, no second place where the household's data can be asked for.
Everything it touches — the recorder, the silence watch, the job, the assistant,
the voice — is already built, tested and behaving. It converts a working
feature from "tap first" to "say the word first" and changes nothing else.

It is also the only one that can be abandoned cheaply. If the wake word is
unreliable or the microphone is not good enough, a day is lost and nothing has
been bought, subscribed to, or exposed to the internet.

Option A is the one to *not* build. It is the most work, it is the only one
that puts a publicly reachable endpoint on the household's calendar, it is the
only one where a child's dentist appointment goes to a third party by design,
and it is the only one with a hard response deadline that the assistant's
tool-using loop will sometimes miss. The Echo is the most convenient hardware
in the house and that is genuinely the argument for it — but it is the wrong
trade for this particular application.

Option B is the right answer *if* the wall's microphone turns out to be
inadequate, or if voice is wanted somewhere other than in front of the wall. It
has the best privacy story of the three by some distance, and the FamilyHub
half of it is small. It is second only because it introduces a second system to
keep working, and because a Voice satellite is a thing to buy and site before
anyone knows whether the feature gets used.

So: **C first, B if the microphone or the range disappoints, A not at all.**

### The test to do first

Stand at the sink, at the table, and at the far door. Tap the microphone on the
wall and ask the assistant something from each. If the transcripts come back
right, C will work. If they do not, skip to B and buy a satellite.
