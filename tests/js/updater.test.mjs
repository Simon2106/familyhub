import { test } from 'node:test';
import assert from 'node:assert/strict';

import { createUpdater, crossedDailyTime, minutesOfDay } from '../../resources/js/updater.js';

const at = (h, m = 0) => new Date(2026, 8, 7, h, m);

/** An updater with time and network under the test's control. */
function harness({ version = 'v1', idleMs = 30_000, minUptimeMs = 0 } = {}) {
    let clock = 1_000_000;
    let clockDate = at(12, 0);
    let latest = version;
    let reloads = 0;
    let fetchFails = false;

    const updater = createUpdater({
        version,
        idleMs,
        minUptimeMs,
        now: () => clock,
        date: () => clockDate,
        reload: () => reloads++,
        fetchVersion: async () => {
            if (fetchFails) throw new Error('offline');
            return latest;
        },
    });

    return {
        updater,
        advance: (ms) => (clock += ms),
        setDate: (d) => (clockDate = d),
        deploy: (v) => (latest = v),
        goOffline: () => (fetchFails = true),
        goOnline: () => (fetchFails = false),
        reloads: () => reloads,
    };
}

test('an unchanged version never reloads', async () => {
    const h = harness();
    h.advance(60_000);

    await h.updater.tick();

    assert.equal(h.reloads(), 0);
});

test('a new version reloads once the display is idle', async () => {
    const h = harness();
    h.deploy('v2');
    h.advance(60_000); // no interaction, so idle

    await h.updater.tick();

    assert.equal(h.reloads(), 1);
});

test('a new version does not reload under someone’s finger', async () => {
    const h = harness();
    h.deploy('v2');

    h.updater.touched();
    h.advance(5_000); // still well inside the idle window

    await h.updater.tick();

    assert.equal(h.reloads(), 0, 'must not reload while the screen is in use');
});

test('a deferred reload happens at the next quiet moment', async () => {
    const h = harness();
    h.deploy('v2');

    h.updater.touched();
    h.advance(5_000);
    await h.updater.tick();
    assert.equal(h.reloads(), 0);

    // Nobody has touched it since; the next poll takes its chance.
    h.advance(40_000);
    await h.updater.tick();

    assert.equal(h.reloads(), 1);
});

test('a fresh page does not reload itself immediately', async () => {
    // Guards against a boot loop when the embedded version and the endpoint
    // briefly disagree mid-deploy.
    const h = harness({ minUptimeMs: 60_000 });
    h.deploy('v2');
    h.advance(31_000); // idle, but only just started

    await h.updater.tick();

    assert.equal(h.reloads(), 0);

    h.advance(60_000);
    await h.updater.tick();

    assert.equal(h.reloads(), 1);
});

test('a failed check is not treated as a new version', async () => {
    const h = harness();
    h.goOffline();
    h.advance(60_000);

    await h.updater.tick();

    assert.equal(h.reloads(), 0, 'a dropped connection must not restart the wall');
});

test('it recovers once the network comes back', async () => {
    const h = harness();
    h.goOffline();
    h.advance(60_000);
    await h.updater.tick();

    h.goOnline();
    h.deploy('v2');
    await h.updater.tick();

    assert.equal(h.reloads(), 1);
});

test('crossing the daily reload time triggers a reload', async () => {
    const h = harness();
    h.setDate(at(3, 44));
    await h.updater.tick();

    h.setDate(at(3, 46));
    h.advance(60_000);
    await h.updater.tick();

    assert.equal(h.reloads(), 1);
});

test('the daily reload fires once, not on every tick after it', async () => {
    const h = harness();
    h.setDate(at(3, 44));
    await h.updater.tick();

    h.setDate(at(3, 46));
    h.advance(60_000);
    await h.updater.tick();

    h.setDate(at(3, 47));
    h.advance(60_000);
    await h.updater.tick();

    assert.equal(h.reloads(), 1);
});

test('the daily reload waits for the display to be idle', async () => {
    const h = harness();
    h.setDate(at(3, 44));
    await h.updater.tick();

    h.updater.touched();
    h.setDate(at(3, 46));
    await h.updater.tick();

    assert.equal(h.reloads(), 0);
});

test('crossedDailyTime handles the midnight wrap', () => {
    assert.equal(crossedDailyTime(at(23, 59), at(4, 0), '03:45'), true);
    assert.equal(crossedDailyTime(at(3, 46), at(3, 47), '03:45'), false);
    assert.equal(crossedDailyTime(at(3, 44), at(3, 45), '03:45'), true);
});

test('a malformed daily time never fires', () => {
    assert.equal(minutesOfDay('nonsense'), null);
    assert.equal(crossedDailyTime(at(3, 44), at(3, 46), 'nonsense'), false);
});
