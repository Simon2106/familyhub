import { strict as assert } from 'node:assert';
import test from 'node:test';

import { createSilenceWatch, followUntilDone, levelOf } from '../../resources/js/listen.js';

/* Loud enough to count as somebody talking, and not. */
const LOUD = 0.5;
const QUIET = 0;

function watching(options = {}) {
    const watch = createSilenceWatch(options);
    watch.start(0);

    return watch;
}

test('it keeps listening while somebody is talking', () => {
    const watch = watching();

    assert.equal(watch.sample(LOUD, 100), null);
    assert.equal(watch.sample(LOUD, 3000), null);
    assert.equal(watch.sample(LOUD, 9000), null);
});

test('a sentence ends after a second and a half of quiet', () => {
    const watch = watching();

    watch.sample(LOUD, 1000);

    // A pause for breath is not the end of a question.
    assert.equal(watch.sample(QUIET, 2000), null);
    assert.equal(watch.sample(QUIET, 2400), null);
    assert.equal(watch.sample(QUIET, 2500), 'silence');
});

test('a pause mid-sentence does not end it', () => {
    const watch = watching();

    watch.sample(LOUD, 500);
    watch.sample(QUIET, 1500);
    watch.sample(LOUD, 1800); // carried on
    assert.equal(watch.sample(QUIET, 3000), null);
    assert.equal(watch.sample(QUIET, 3400), 'silence');
});

test('it gives up when nobody says anything at all', () => {
    const watch = watching();

    assert.equal(watch.sample(QUIET, 4000), null);
    assert.equal(watch.sample(QUIET, 5000), 'nothing');
});

test('it stops at the ceiling however much is still being said', () => {
    // Whisper is charged by the second, and a wall left recording is a wall
    // recording the room.
    const watch = watching();

    watch.sample(LOUD, 14_000);
    assert.equal(watch.sample(LOUD, 15_000), 'limit');
});

test('the ceiling and the silence rule can be tuned', () => {
    const watch = watching({ maxMs: 3000, silenceMs: 500 });

    watch.sample(LOUD, 100);
    assert.equal(watch.sample(QUIET, 700), 'silence');
});

test('a sample before start decides nothing', () => {
    assert.equal(createSilenceWatch().sample(LOUD, 100), null);
});

/* ------------------------------- loudness ------------------------------- */

test('silence measures zero and a full swing measures one', () => {
    // Byte-domain data from an AnalyserNode is centred on 128.
    assert.equal(levelOf(new Uint8Array([128, 128, 128, 128])), 0);
    assert.equal(levelOf(new Uint8Array([0, 255, 0, 255])) > 0.9, true);
    assert.equal(levelOf(new Uint8Array([])), 0);
    assert.equal(levelOf(null), 0);
});

/* ------------------------------- following ------------------------------ */

function follower(states, options = {}) {
    const seen = [];
    let index = 0;

    return {
        seen,
        run: () =>
            followUntilDone({
                id: 'q1',
                fetchState: async () => {
                    const state = states[Math.min(index, states.length - 1)];
                    index += 1;

                    return state;
                },
                onState: (state) => seen.push(state.status),
                sleep: async () => {},
                ...options,
            }),
    };
}

test('it follows a question to its answer', async () => {
    const { run, seen } = follower([
        { status: 'transcribing' },
        { status: 'thinking', transcript: 'what is for tea' },
        { status: 'done', answer: 'Fish pie.' },
    ]);

    const final = await run();

    assert.equal(final.answer, 'Fish pie.');
    assert.deepEqual(seen, ['transcribing', 'thinking', 'done']);
});

test('a failure ends the following too', async () => {
    const { run } = follower([{ status: 'failed', error: "I didn't catch that." }]);

    assert.equal((await run()).error, "I didn't catch that.");
});

test('a dropped poll is not a failed answer', async () => {
    // The job is still running on the server; only the network blinked.
    let calls = 0;

    const final = await followUntilDone({
        id: 'q1',
        fetchState: async () => {
            calls += 1;

            if (calls < 3) throw new Error('offline');

            return { status: 'done', answer: 'Fish pie.' };
        },
        sleep: async () => {},
    });

    assert.equal(final.answer, 'Fish pie.');
    assert.equal(calls, 3);
});

test('it gives up rather than polling for ever', async () => {
    let clock = 0;

    const final = await followUntilDone({
        id: 'q1',
        fetchState: async () => ({ status: 'thinking' }),
        sleep: async () => {},
        now: () => (clock += 5000),
        timeoutMs: 20_000,
    });

    assert.equal(final.status, 'failed');
    assert.match(final.error, /too long/);
});
