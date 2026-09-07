/**
 * Long-press drag between grid cells.
 *
 * HTML5 drag-and-drop does not work on iOS Safari, and the wall is an iPad, so
 * this is built on pointer events instead. The press-and-hold is not decorative:
 * a drag that begins on pointerdown makes the grid impossible to scroll on a
 * touch screen, because every attempt to scroll picks a meal up instead.
 *
 * Kept free of DOM lookups and timers of its own so it can be tested; the caller
 * supplies both.
 */
export function createDragBoard({
    holdMs = 350,
    moveTolerance = 10,
    onDrop = () => {},
    onChange = () => {},
    elementFromPoint = null,
    setTimer = (fn, ms) => setTimeout(fn, ms),
    clearTimer = (id) => clearTimeout(id),
} = {}) {
    let pending = null;
    let timer = null;

    const state = {
        /** The payload being dragged, or null when nothing is. */
        dragging: null,
        /** Pointer position, for drawing the thing under the finger. */
        x: 0,
        y: 0,
        /** The cell key currently under the pointer. */
        over: null,
    };

    const announce = () => onChange({ ...state });

    function reset() {
        if (timer !== null) clearTimer(timer);

        timer = null;
        pending = null;
        state.dragging = null;
        state.over = null;

        announce();
    }

    return {
        state,

        /** True once the hold has completed and a real drag is under way. */
        get isDragging() {
            return state.dragging !== null;
        },

        /**
         * A pointer went down on something draggable. Nothing is picked up yet
         * — the hold has to survive `holdMs` without the finger wandering.
         */
        down(event, payload) {
            pending = { payload, x: event.clientX, y: event.clientY };

            timer = setTimer(() => {
                if (!pending) return;

                state.dragging = pending.payload;
                state.x = pending.x;
                state.y = pending.y;

                announce();
            }, holdMs);
        },

        /**
         * @returns {boolean} whether the gesture became a drag, so the caller
         *                    knows whether to stop the page scrolling.
         */
        move(event) {
            if (pending && !state.dragging) {
                // Wandered before the hold completed: this was a scroll.
                const drifted =
                    Math.abs(event.clientX - pending.x) > moveTolerance ||
                    Math.abs(event.clientY - pending.y) > moveTolerance;

                if (drifted) reset();

                return false;
            }

            if (!state.dragging) return false;

            state.x = event.clientX;
            state.y = event.clientY;
            state.over = cellUnder(event);

            announce();

            return true;
        },

        up(event) {
            if (!state.dragging) {
                reset();

                return false;
            }

            const target = cellUnder(event) ?? state.over;
            const payload = state.dragging;

            reset();

            // Dropping a meal back where it started is a no-op, not a move.
            if (target && target !== payload.from) {
                onDrop(payload, target);

                return true;
            }

            return false;
        },

        cancel: reset,
    };

    /** The drop target under the pointer, identified by a data attribute. */
    function cellUnder(event) {
        const at = elementFromPoint ?? ((x, y) => document.elementFromPoint(x, y));
        const element = at(event.clientX, event.clientY);

        return element?.closest?.('[data-cell]')?.dataset?.cell ?? null;
    }
}
