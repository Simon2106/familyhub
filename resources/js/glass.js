/**
 * How bright the thing behind the glass is.
 *
 * A panel at 18% white reads beautifully over a hedge and disappears over a
 * white shirt. Rather than pick a tint that is wrong half the time, the wall
 * samples the photograph once — when the photograph changes, never per frame
 * — and tells each panel whether it is sitting over something bright.
 *
 * Sampling is done at a thumbnail size, because the question is "roughly how
 * light is this corner" and a quarter of a million pixels answers it no
 * better than a few hundred.
 */

/** Below this a panel is over something dark enough to leave alone. */
export const BRIGHT = 0.62;

/** The thumbnail the image is sampled at. Small on purpose. */
export const SAMPLE = 32;

/**
 * Relative luminance of one region of an image, 0 to 1.
 *
 * The region is given in fractions of the image, so a caller can ask about
 * the corner a panel actually sits in without knowing the image's size.
 */
export function luminanceOf(ctx, region = { x: 0, y: 0, w: 1, h: 1 }, size = SAMPLE) {
    const x = Math.floor(region.x * size);
    const y = Math.floor(region.y * size);
    const w = Math.max(1, Math.floor(region.w * size));
    const h = Math.max(1, Math.floor(region.h * size));

    const { data } = ctx.getImageData(x, y, w, h);

    let total = 0;

    for (let i = 0; i < data.length; i += 4) {
        // Rec. 709, which is what "how bright does this look" means.
        total += (0.2126 * data[i] + 0.7152 * data[i + 1] + 0.0722 * data[i + 2]) / 255;
    }

    return total / (data.length / 4);
}

/**
 * Where a panel sits within the frame, as fractions.
 *
 * A panel in the bottom-left corner should be asking about the bottom-left
 * of the photograph, not about its average — a sunset is bright at the top
 * and black at the bottom, and its average is a lie about both.
 */
export function regionFor(panel, frame) {
    const clamp = (n) => Math.max(0, Math.min(1, n));

    const x = clamp((panel.left - frame.left) / frame.width);
    const y = clamp((panel.top - frame.top) / frame.height);

    return {
        x,
        y,
        w: clamp(panel.width / frame.width) || 0.1,
        h: clamp(panel.height / frame.height) || 0.1,
    };
}

/**
 * Draw an image into a small offscreen canvas, ready to be sampled.
 *
 * Null when the browser will not allow it — a cross-origin photograph taints
 * the canvas and getImageData throws. The wall keeps its default tint in
 * that case, which is the right answer: a guess is worse than a default.
 */
export function sampler(image, size = SAMPLE) {
    try {
        const canvas = document.createElement('canvas');

        canvas.width = size;
        canvas.height = size;

        const ctx = canvas.getContext('2d', { willReadFrequently: true });

        if (!ctx) return null;

        ctx.drawImage(image, 0, 0, size, size);

        // Proves the canvas is readable before anything depends on it.
        ctx.getImageData(0, 0, 1, 1);

        return ctx;
    } catch {
        return null;
    }
}

/**
 * Mark every panel over a bright part of the photograph.
 *
 * Returns how many were marked, which is what a test can assert on.
 */
export function tintPanels(panels, frame, ctx, bright = BRIGHT) {
    if (!ctx) return 0;

    let marked = 0;

    for (const panel of panels) {
        const box = panel.getBoundingClientRect();

        if (box.width === 0 || box.height === 0) continue;

        const over = luminanceOf(ctx, regionFor(box, frame)) >= bright;

        panel.dataset.bright = over ? '1' : '0';

        if (over) marked++;
    }

    return marked;
}
