/**
 * The UI audit: tap targets and colour contrast, measured in a real browser.
 *
 * Written because guessing at this is hopeless. Tailwind computes colours to
 * oklch(), backgrounds are inherited through however many transparent
 * ancestors, and a "44px button" is only 44px if nothing above it shrank the
 * row. The only honest answer comes from getComputedStyle and
 * getBoundingClientRect on the actual pages at the actual viewports.
 *
 * Not part of `npm test`: it needs a running server, a seeded database, and
 * Playwright, which is deliberately not a dependency of this project — it is
 * a several-hundred-megabyte browser download for a tool run by hand a few
 * times a year.
 *
 *   npm i --no-save playwright && npx playwright install chromium
 *   php artisan serve --port=8321
 *   DISPLAY_TOKEN=$(grep FAMILYHUB_DISPLAY_TOKEN .env | cut -d= -f2) node tests/browser/ui-audit.mjs
 *
 * Environment: BASE (default http://127.0.0.1:8321), DISPLAY_TOKEN,
 * EMAIL and PASSWORD for the /app pages.
 */

import { chromium } from 'playwright';

const BASE = process.env.BASE ?? 'http://127.0.0.1:8321';
const TOKEN = process.env.DISPLAY_TOKEN ?? '';
const EMAIL = process.env.EMAIL ?? 'parent1@example.com';
const PASSWORD = process.env.PASSWORD ?? 'password';

/** Everything on the wall and in a pocket must be reachable with a thumb. */
const MIN_TARGET = 44;

/* -------------------------------------------------------------------------
 * The probes. These run inside the page.
 * ---------------------------------------------------------------------- */

function targetProbe(min) {
    const seen = new Set();
    const small = [];

    for (const el of document.querySelectorAll('a[href], button, input, select, textarea, [role="button"]')) {
        const box = el.getBoundingClientRect();

        if (box.width === 0 || box.height === 0) continue;

        const style = getComputedStyle(el);

        if (style.visibility === 'hidden' || style.display === 'none') continue;

        // A checkbox is allowed to be small if the label around it is not:
        // the label is what a thumb actually lands on.
        const target = el.closest('label') ?? el;
        const reach = target.getBoundingClientRect();

        if (reach.height >= min && reach.width >= min) continue;

        const label = (el.getAttribute('aria-label') || el.textContent.trim().slice(0, 40) || el.tagName)
            .replace(/\s+/g, ' ');
        const key = `${el.tagName}|${label}|${Math.round(reach.width)}x${Math.round(reach.height)}`;

        if (seen.has(key)) continue;

        seen.add(key);
        small.push({ label, w: Math.round(reach.width), h: Math.round(reach.height) });
    }

    return small;
}

function contrastProbe() {
    // Tailwind 4 computes to oklch(). Paint the colour and read the pixel back
    // rather than trying to parse a colour space with a regex — the first
    // version of this did, and reported near-white text as 1.09:1.
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 1;

    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const cache = new Map();

    function rgb(colour) {
        if (cache.has(colour)) return cache.get(colour);

        ctx.clearRect(0, 0, 1, 1);
        ctx.fillStyle = '#000';
        ctx.fillStyle = colour;

        if (ctx.fillStyle === '#000' && !/^#0{3,6}$|black|rgb\(0, 0, 0\)/i.test(colour)) {
            cache.set(colour, null);

            return null;
        }

        ctx.fillRect(0, 0, 1, 1);

        const d = ctx.getImageData(0, 0, 1, 1).data;
        const value = [d[0], d[1], d[2], d[3] / 255];

        cache.set(colour, value);

        return value;
    }

    function luminance(colour) {
        const v = rgb(colour);

        if (!v) return null;

        const f = (x) => {
            x /= 255;

            return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
        };

        return 0.2126 * f(v[0]) + 0.7152 * f(v[1]) + 0.0722 * f(v[2]);
    }

    const opaque = (c) => {
        const v = rgb(c);

        return Boolean(v) && v[3] > 0.95;
    };

    /** The first opaque background above this element — what it really sits on. */
    function backgroundOf(el) {
        let node = el;

        while (node) {
            const colour = getComputedStyle(node).backgroundColor;

            if (opaque(colour)) return colour;

            node = node.parentElement;
        }

        const body = getComputedStyle(document.body).backgroundColor;

        return opaque(body) ? body : getComputedStyle(document.documentElement).backgroundColor;
    }

    const bad = [];
    const seen = new Set();

    for (const el of document.querySelectorAll('p,span,a,button,h1,h2,h3,label,li,td,div')) {
        // Only the element's own text: a wrapper inherits nothing useful.
        const text = [...el.childNodes]
            .filter((n) => n.nodeType === 3)
            .map((n) => n.textContent.trim())
            .join('')
            .trim();

        if (text.length < 2) continue;

        const box = el.getBoundingClientRect();

        if (box.width === 0 || box.height === 0) continue;

        const style = getComputedStyle(el);

        if (style.visibility === 'hidden' || parseFloat(style.opacity) < 0.5) continue;

        const fg = luminance(style.color);
        const bg = luminance(backgroundOf(el));

        if (fg === null || bg === null) continue;

        const ratio = (Math.max(fg, bg) + 0.05) / (Math.min(fg, bg) + 0.05);
        const size = parseFloat(style.fontSize);
        const bold = parseInt(style.fontWeight, 10) >= 700;
        const need = size >= 24 || (size >= 18.66 && bold) ? 3 : 4.5;

        if (ratio >= need) continue;

        const key = text.slice(0, 30) + style.color;

        if (seen.has(key)) continue;

        seen.add(key);
        bad.push({ text: text.slice(0, 42), ratio: +ratio.toFixed(2), need, colour: style.color });
    }

    return bad;
}

/* ---------------------------------------------------------------------- */

const problems = [];

async function inspect(page, name) {
    await page.waitForTimeout(800);

    const small = await page.evaluate(targetProbe, MIN_TARGET);
    const contrast = await page.evaluate(contrastProbe);

    if (small.length === 0 && contrast.length === 0) {
        console.log(`  ${name}: clean`);

        return;
    }

    console.log(`  ${name}:`);

    for (const s of small) console.log(`    tap  ${s.w}x${s.h}  "${s.label}"`);
    for (const c of contrast) console.log(`    text ${c.ratio}:1 (needs ${c.need})  ${c.colour}  "${c.text}"`);

    problems.push({ name, small: small.length, contrast: contrast.length });
}

const browser = await chromium.launch();

for (const dark of [false, true]) {
    console.log(`\n${dark ? 'DARK' : 'LIGHT'}`);

    const theme = () =>
        dark
            ? (page) => page.addInitScript(() => document.addEventListener('DOMContentLoaded', () => document.documentElement.classList.add('dark')))
            : () => Promise.resolve();

    if (TOKEN) {
        const wall = await browser.newPage({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 2, hasTouch: true });

        await theme()(wall);
        await wall.goto(`${BASE}/display?token=${TOKEN}`, { waitUntil: 'networkidle' });
        await inspect(wall, 'wall home');

        for (const tab of ['Lists', 'Meals', 'Review', 'Switches', 'Photos']) {
            await wall.getByRole('button', { name: tab }).first().click().catch(() => {});
            await inspect(wall, `wall ${tab.toLowerCase()}`);
        }

        await wall.close();
    }

    const phone = await browser.newPage({ viewport: { width: 440, height: 956 }, deviceScaleFactor: 3, hasTouch: true });

    await theme()(phone);
    await phone.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await phone.fill('input[type="email"]', EMAIL);
    await phone.fill('input[type="password"]', PASSWORD);
    await phone.press('input[type="password"]', 'Enter');
    await phone.waitForURL('**/app');

    for (const path of [
        '/app', '/app/meals', '/app/lists', '/app/kids', '/app/week', '/app/photos',
        '/app/recipes', '/app/switches', '/app/notifications',
        '/admin', '/admin/calendars', '/admin/chores',
    ]) {
        await phone.goto(`${BASE}${path}`, { waitUntil: 'networkidle' });
        await inspect(phone, path);
    }

    await phone.close();
}

await browser.close();

console.log(problems.length === 0 ? '\nNothing to fix.' : `\n${problems.length} pages with something to fix.`);
process.exit(problems.length === 0 ? 0 : 1);
