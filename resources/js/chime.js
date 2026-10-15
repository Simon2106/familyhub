/**
 * The noise a finished timer makes.
 *
 * Three sine waves rather than an audio file: the kiosk may have no speakers
 * worth a download, and a tone somebody can hear over an extractor fan is
 * cheaper to synthesise than to fetch. Shared so that cook mode and the
 * timer strip cannot drift into making two different noises.
 */
export function chime(times = 1) {
    try {
        const context = new (window.AudioContext || window.webkitAudioContext)();

        const notes = [];

        for (let round = 0; round < Math.max(1, times); round++) {
            const offset = round * 1.2;

            notes.push([offset, 880], [offset + 0.35, 880], [offset + 0.7, 1174]);
        }

        for (const [at, hz] of notes) {
            const oscillator = context.createOscillator();
            const gain = context.createGain();

            oscillator.frequency.value = hz;
            gain.gain.setValueAtTime(0.0001, context.currentTime + at);
            gain.gain.exponentialRampToValueAtTime(0.3, context.currentTime + at + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + at + 0.25);

            oscillator.connect(gain).connect(context.destination);
            oscillator.start(context.currentTime + at);
            oscillator.stop(context.currentTime + at + 0.3);
        }

        setTimeout(() => context.close(), 2000 + Math.max(1, times) * 1200);
    } catch {
        // No audio, or a browser that will not start one without a tap. The
        // banner is the part somebody at the wall will see anyway.
    }
}
