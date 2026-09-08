/**
 * The wall's microphone.
 *
 * Tap, talk, stop talking. Nobody in a kitchen wants to hold a button down
 * with wet hands, so the end of a question has to be noticed rather than
 * announced — and noticed conservatively, because cutting somebody off
 * mid-sentence is worse than a second of dead air on the end.
 *
 * The deciding is kept free of the browser so it can be tested; the recording
 * itself is thin glue over MediaRecorder, which cannot be.
 */

export const DEFAULTS = {
    // How much quiet ends a question.
    silenceMs: 1500,
    // The hard ceiling. Whisper is charged by the second and a wall left
    // recording is a wall recording the room.
    maxMs: 15_000,
    // How long to wait for somebody to actually start, if they never do.
    patienceMs: 5000,
    // Root-mean-square level, 0–1, above which the room counts as speaking.
    // Generous: a kitchen has a fridge in it.
    threshold: 0.02,
};

/**
 * Watches the loudness of a room and says when to stop recording.
 *
 * Returns null while it should keep going, or why it stopped: 'silence' after
 * a sentence, 'nothing' when nobody ever spoke, 'limit' at the ceiling.
 */
export function createSilenceWatch(options = {}) {
    const config = { ...DEFAULTS, ...options };

    let startedAt = null;
    let lastLoudAt = null;
    let heardAnything = false;

    return {
        get heardAnything() {
            return heardAnything;
        },

        start(at) {
            startedAt = at;
            lastLoudAt = null;
            heardAnything = false;
        },

        /** @param level root-mean-square amplitude, 0–1 */
        sample(level, at) {
            if (startedAt === null) return null;

            if (at - startedAt >= config.maxMs) return "limit";

            if (level >= config.threshold) {
                heardAnything = true;
                lastLoudAt = at;

                return null;
            }

            if (heardAnything) {
                return at - lastLoudAt >= config.silenceMs ? "silence" : null;
            }

            // Nobody has said anything at all yet. Give up eventually rather
            // than recording an empty room for the full fifteen seconds.
            return at - startedAt >= config.patienceMs ? "nothing" : null;
        },
    };
}

/** The loudness of one frame of audio, 0–1. */
export function levelOf(samples) {
    if (!samples || samples.length === 0) return 0;

    let sum = 0;

    for (const sample of samples) {
        // Byte-domain data from an AnalyserNode is centred on 128.
        const centred = (sample - 128) / 128;

        sum += centred * centred;
    }

    return Math.sqrt(sum / samples.length);
}

/**
 * Watches a question being answered, until it is.
 *
 * Polling rather than a socket on purpose: this has to work on a wall whose
 * household has not set Reverb up, and it is over in a few seconds either way.
 */
export async function followUntilDone({
    id,
    fetchState,
    onState = () => {},
    intervalMs = 700,
    timeoutMs = 60_000,
    sleep = (ms) => new Promise((r) => setTimeout(r, ms)),
    now = () => Date.now(),
} = {}) {
    const startedAt = now();
    let last = null;

    for (;;) {
        let state;

        try {
            state = await fetchState(id);
        } catch {
            // A dropped poll is not a failed answer — the job is still running
            // on the server. Try again until the deadline.
            state = null;
        }

        if (state && state.status !== last) {
            last = state.status;
            onState(state);
        } else if (state) {
            onState(state);
        }

        if (state && (state.status === "done" || state.status === "failed")) {
            return state;
        }

        if (now() - startedAt >= timeoutMs) {
            return {
                status: "failed",
                error: "That took too long. Try again.",
            };
        }

        await sleep(intervalMs);
    }
}
