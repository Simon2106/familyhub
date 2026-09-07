/**
 * Realtime updates, when there are any to have.
 *
 * Entirely optional: with no Reverb key configured this does nothing at all and
 * the wall carries on polling. That is deliberate — Reverb is a second process
 * to keep alive, and an install without one should behave exactly as it did
 * before, not worse.
 *
 * Kept free of Echo's own imports until it knows there is something to connect
 * to, so an unconfigured install pays nothing for it.
 */
export async function startEcho(config) {
    if (!config?.key) return null;

    const [{ default: Echo }, { default: Pusher }] = await Promise.all([
        import('laravel-echo'),
        import('pusher-js'),
    ]);

    window.Pusher = Pusher;

    return new Echo({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        // Reverb is served under a path of its own so nginx can tell its
        // traffic apart from the phone UI, which also lives at /app.
        wsPath: config.path || '',
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        // The wall is behind Guided Access on a shelf; nobody is going to
        // reload it when the network blips.
        activityTimeout: 30000,
        pongTimeout: 10000,
    });
}

/**
 * Whether the socket is currently carrying traffic.
 *
 * The wall uses this to decide how hard to poll: connected means the nudges
 * are arriving and a slow safety net is enough; disconnected means the poll is
 * the only thing keeping the tiles honest.
 */
export function watchConnection(echo, onChange) {
    const connection = echo?.connector?.pusher?.connection;

    if (!connection) return () => {};

    const report = () => onChange(connection.state === 'connected');

    connection.bind('state_change', report);
    report();

    return () => connection.unbind('state_change', report);
}
