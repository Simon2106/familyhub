{{--
    The displacement map the glass edge refracts through.

    Rendered once per page, hidden, and referenced by url(#glass-refraction)
    from the .glass ring. Turbulence at a very low frequency gives a slow,
    liquid wobble along the edge rather than noise; the scale is how far the
    light bends, and above about 14 it stops looking like glass and starts
    looking like a fault.

    Not rendered at all when the household has asked for lighter glass — a
    filter nothing references still costs the compositor nothing, but an
    unused SVG in the DOM of a kiosk is one more thing to be wrong.
--}}
<svg aria-hidden="true" focusable="false"
     style="position:absolute;width:0;height:0;pointer-events:none;">
    <filter id="glass-refraction" x="-20%" y="-20%" width="140%" height="140%"
            color-interpolation-filters="sRGB">
        <feTurbulence type="fractalNoise" baseFrequency="0.008" numOctaves="2" seed="7" result="noise" />
        <feGaussianBlur in="noise" stdDeviation="1.2" result="soft" />
        <feDisplacementMap in="SourceGraphic" in2="soft" scale="12"
                           xChannelSelector="R" yChannelSelector="G" />
    </filter>
</svg>
