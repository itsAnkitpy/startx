{{--
    The StartX ground: the palette, the two type families, and the drifting glow and
    fine grid behind every page a visitor sees before signing in.

    Self-contained on purpose. It is included by the sign-in page, which renders inside
    Filament's own stylesheet rather than ours, and by the wrong-address page, which has
    to render when no front-end build exists at all. Nothing here needs `npm run build`.

    Every custom property is prefixed `--sx-` so it cannot collide with Filament's own.
--}}
<style>
    @import url('https://fonts.bunny.net/css?family=inter:400,500,600|space-grotesk:500,600,700&display=swap');

    :root {
        --sx-primary: #6e88ff;
        --sx-primary-bright: #8fa3ff;
        --sx-primary-deep: #5a7bfe;
        --sx-ground: #0a0e14;
        --sx-panel: rgba(18, 22, 30, 0.55);
        --sx-hair: rgba(110, 136, 255, 0.18);
        --sx-edge: rgba(255, 255, 255, 0.06);
        --sx-text: #f8fafc;
        --sx-muted: #94a3b8;
        --sx-faint: #475569;
        --sx-placeholder: #6b7280;
        --sx-good: #34d399;
        --sx-good-text: #a7f3d0;
        --sx-bad: #fb7185;
        --sx-bad-text: #fecdd3;
        --sx-body: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        --sx-display: 'Space Grotesk', 'Inter', ui-sans-serif, system-ui, sans-serif;
    }

    /* Both layers are fixed, so they are painted against the viewport and add
       nothing to the page's scroll height — an inset overhang on an absolute
       element invents an empty scrollable band below the fold. */
    .sx-glow,
    .sx-grid {
        position: fixed;
        pointer-events: none;
        z-index: 0;
    }

    .sx-glow {
        inset: -20%;
        background:
            radial-gradient(40% 40% at 30% 32%, rgba(110, 136, 255, 0.26), transparent 70%),
            radial-gradient(34% 34% at 72% 66%, rgba(143, 163, 255, 0.16), transparent 70%);
        filter: blur(36px);
        animation: sx-drift 20s ease-in-out infinite alternate;
    }

    @keyframes sx-drift {
        from { transform: translate3d(-3%, -2%, 0) scale(1); }
        to   { transform: translate3d(4%, 3%, 0) scale(1.12); }
    }

    .sx-grid {
        inset: 0;
        background-image:
            linear-gradient(rgba(255, 255, 255, 0.035) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255, 255, 255, 0.035) 1px, transparent 1px);
        background-size: 46px 46px;
        -webkit-mask-image: radial-gradient(circle at 50% 42%, #000 55%, transparent 100%);
        mask-image: radial-gradient(circle at 50% 42%, #000 55%, transparent 100%);
    }

    @media (prefers-reduced-motion: reduce) {
        .sx-glow { animation: none; }
    }
</style>

<div class="sx-glow"></div>
<div class="sx-grid"></div>
