// Story composition timeline. Reads window.STORY (data.js), never parses markup from it.
(() => {
    const story = window.STORY;
    const duration = story.duration;
    const maxWidth = 960;
    const minFont = 44;
    const maxFont = 104;
    const maxLines = 3;

    const headline = document.getElementById('headline');
    const scrim = document.getElementById('scrim');

    const words = story.headline === '' ? [] : story.headline.split(' ');
    words.forEach((word) => {
        const span = document.createElement('span');
        span.className = 'wd';
        span.textContent = word;
        headline.appendChild(span);
    });

    const fits = (size) => {
        const lineHeight = size * 1.12;
        const lines = Math.round(headline.offsetHeight / lineHeight);
        return headline.scrollWidth <= maxWidth && lines <= maxLines;
    };

    // Fit-to-width: shrink until the block sits inside the side margins, then break long words as a last resort.
    const fit = () => {
        if (words.length === 0) {
            return;
        }
        let size = maxFont;
        headline.style.fontSize = `${size}px`;
        while (size > minFont && !fits(size)) {
            size -= 4;
            headline.style.fontSize = `${size}px`;
        }
        if (headline.scrollWidth > maxWidth || Array.from(headline.children).some((el) => el.scrollWidth > maxWidth)) {
            headline.classList.add('breakall');
        }
        // Clear at the very top (baked-in logo and notice sit there), darkest behind the text, fading out below it.
        const bottom = headline.offsetTop + headline.offsetHeight;
        const end = bottom + 320;
        scrim.style.height = `${end}px`;
        scrim.style.background = `linear-gradient(to bottom, rgba(0,0,0,0) 0px, rgba(0,0,0,0.55) 260px, rgba(0,0,0,0.55) ${bottom + 30}px, rgba(0,0,0,0) ${end}px)`;
    };

    fit();
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(fit);
    }

    window.__timelines = window.__timelines || {};
    const tl = gsap.timeline({ paused: true });

    // Continuous zoom-out from frame one; the last frame equals the photo, so baked-in overlays stay inside the frame.
    tl.fromTo('#photo', { scale: 1.02 }, { scale: 1, duration, ease: 'none' }, 0);

    if (words.length > 0) {
        const each = Math.min(0.16, (duration * 0.4) / words.length);
        tl.to('#scrim', { opacity: 1, duration: 0.8, ease: 'power1.out' }, 0.2);
        tl.fromTo('#headline .wd', { y: 46, opacity: 0, filter: 'blur(10px)' }, { y: 0, opacity: 1, filter: 'blur(0px)', duration: 0.65, ease: 'power3.out', stagger: each }, 0.5);
    }

    window.__timelines.main = tl;
})();
