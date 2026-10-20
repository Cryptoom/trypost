// Story composition timeline. It replays window.STORY (data.js): every number comes from the service,
// every text is set with textContent, and no markup is ever parsed from the data.
(() => {
    const story = window.STORY;
    const $ = (id) => document.getElementById(id);
    const root = $('root');
    const times = story.timeline;

    const hex = /^#[0-9A-F]{6}$/;
    root.style.setProperty('--accent', hex.test(story.accent) ? story.accent : '#FFFFFF');
    root.style.setProperty('--accent-text', hex.test(story.accentText) ? story.accentText : '#0A2A3D');

    // ---- text ----------------------------------------------------------------------------------
    const blockEls = [$('blkA'), $('blkB')];
    const fillBlock = (el, content) => {
        const hl = el.querySelector('.hl');
        const sub = el.querySelector('.sub');
        (content.headline || []).forEach((word) => {
            const span = document.createElement('span');
            span.className = word.a ? 'wd pop' : 'wd';
            span.textContent = word.t;
            hl.appendChild(span);
        });
        (content.subline || []).forEach((part, index) => {
            if (index > 0) {
                const dot = document.createElement('span');
                dot.className = 'dot';
                dot.textContent = '·';
                sub.appendChild(dot);
            }
            sub.appendChild(document.createTextNode(part));
        });
        if (!content.headline) {
            hl.style.display = 'none';
        }
        if (!content.subline) {
            sub.style.display = 'none';
        }
    };
    story.blocks.forEach((content, index) => fillBlock(blockEls[index], content));
    blockEls.forEach((el, index) => {
        if (index >= story.blocks.length) {
            el.style.display = 'none';
        }
    });

    const badge = $('badge');
    if (story.badge) {
        badge.textContent = story.badge;
    } else {
        badge.style.display = 'none';
    }

    const end = $('end');
    if (story.ending) {
        end.querySelector('.name').textContent = story.ending.name || '';
        end.querySelector('.line').textContent = story.ending.line || '';
        if (!story.ending.name) {
            end.querySelector('.name').style.display = 'none';
        }
        if (!story.ending.line) {
            end.querySelector('.line').style.display = 'none';
        }
    } else {
        end.style.display = 'none';
    }

    const ai = $('ai');
    if (story.aiNotice) {
        ai.textContent = story.aiNotice;
    } else {
        ai.style.display = 'none';
    }

    const logo = $('logo');
    if (story.logo) {
        const img = $('logoImg');
        img.src = story.logo.file;
        img.style.width = `${story.logo.w}px`;
        img.style.height = `${story.logo.h}px`;
    } else {
        logo.style.display = 'none';
    }

    // ---- fit to width --------------------------------------------------------------------------
    // Shrinks the font until the element fits its width and line count; a single word wider than the
    // block is broken as the last resort. Every text element goes through this.
    const fitBlock = (el, { maxWidth, maxFont, minFont, maxLines, lineHeight, step = 4 }) => {
        let size = maxFont;
        el.style.fontSize = `${size}px`;
        const fits = () => el.scrollWidth <= maxWidth && Math.round(el.offsetHeight / (size * lineHeight)) <= maxLines;
        while (size > minFont && !fits()) {
            size -= step;
            el.style.fontSize = `${size}px`;
        }
        if (el.scrollWidth > maxWidth || Array.from(el.children).some((child) => child.scrollWidth > maxWidth)) {
            el.classList.add('breakall');
        }
    };

    const fitOneLine = (el, { maxWidth, maxFont, minFont }) => {
        let size = maxFont;
        el.style.fontSize = `${size}px`;
        while (size > minFont && el.scrollWidth > maxWidth) {
            size -= 2;
            el.style.fontSize = `${size}px`;
        }
    };

    const layout = () => {
        let lowest = 300;
        blockEls.forEach((el, index) => {
            if (index >= story.blocks.length) {
                return;
            }
            fitBlock(el.querySelector('.hl'), { maxWidth: 960, maxFont: 96, minFont: 44, maxLines: 3, lineHeight: 1.12 });
            fitBlock(el.querySelector('.sub'), { maxWidth: 960, maxFont: 44, minFont: 26, maxLines: 2, lineHeight: 1.2, step: 2 });
            lowest = Math.max(lowest, el.offsetTop + el.offsetHeight);
        });

        if (story.badge) {
            fitOneLine(badge, { maxWidth: 360, maxFont: 48, minFont: 22 });
            // Right edge stays 80 px inside the frame; the badge sits under the text block.
            badge.style.top = `${lowest + 30}px`;
            lowest = Math.max(lowest, badge.offsetTop + badge.offsetHeight);
        }

        const scrim = $('scrimTop');
        const scrimEnd = lowest + 320;
        scrim.style.height = `${scrimEnd}px`;
        scrim.style.background = `linear-gradient(to bottom, rgba(10,42,61,0) 0px, rgba(10,42,61,0.72) 260px, rgba(10,42,61,0.72) ${lowest + 30}px, rgba(10,42,61,0) ${scrimEnd}px)`;

        if (story.ending) {
            fitOneLine(end.querySelector('.name'), { maxWidth: 840, maxFont: 62, minFont: 30 });
            fitBlock(end.querySelector('.line'), { maxWidth: 840, maxFont: 40, minFont: 24, maxLines: 2, lineHeight: 1.2, step: 2 });
            // The block ends at y=1540, well above the lower story controls.
            end.style.top = `${1540 - end.offsetHeight}px`;
        }

        if (story.aiNotice) {
            let size = 20;
            ai.style.fontSize = `${size}px`;
            while (size > 14 && ai.offsetHeight > 500) {
                size -= 1;
                ai.style.fontSize = `${size}px`;
            }
        }
    };

    layout();
    if (document.fonts) {
        const probe = `${story.font.weight} 48px "${story.font.family}"`;
        document.fonts.load(probe, root.textContent || ' ').then(layout, () => {});
        document.fonts.ready.then(layout);
    }

    // ---- camera and effect ----------------------------------------------------------------------
    window.__timelines = window.__timelines || {};
    const tl = gsap.timeline({ paused: true });
    const motion = story.motion;

    motion.segments.forEach((segment, index) => {
        tl.fromTo('#move', segment.from, { ...segment.to, duration: segment.duration, ease: segment.ease, immediateRender: index === 0 }, segment.at);
    });

    if (motion.pull) {
        // Focus pull: soft and dim at first, then sharp. The sharp copy behind fills the blurred edge.
        $('photoBack').style.display = 'block';
        tl.fromTo('#photoBack', { filter: `brightness(${motion.pull.brightness})` }, { filter: 'brightness(1)', duration: motion.pull.duration, ease: 'power2.out' }, 0);
        tl.fromTo('#photo', { filter: `blur(${motion.pull.blur}px) brightness(${motion.pull.brightness})` }, { filter: 'blur(0px) brightness(1)', duration: motion.pull.duration, ease: 'power2.out' }, 0);
    }
    if (motion.vignette) {
        tl.to('#vignette', { opacity: motion.vignette.opacity, duration: motion.vignette.duration }, motion.vignette.at);
    }
    if (motion.leak) {
        tl.fromTo('#leak', { x: 0, opacity: 0 }, { x: motion.leak.travel, opacity: 1, duration: motion.leak.duration, ease: 'sine.inOut' }, motion.leak.at);
        tl.to('#leak', { opacity: 0, duration: motion.leak.fade }, motion.leak.at + motion.leak.duration - motion.leak.fade);
    }
    if (motion.motes) {
        const box = $('bokeh');
        motion.motes.forEach((mote) => {
            const el = document.createElement('div');
            el.className = 'b';
            el.style.width = `${mote.size}px`;
            el.style.height = `${mote.size}px`;
            el.style.left = `${mote.left}px`;
            el.style.top = `${mote.top}px`;
            box.appendChild(el);
            tl.fromTo(el, { y: 0, opacity: 0 }, { y: -mote.rise, opacity: mote.peak, duration: mote.riseTime, ease: 'sine.out' }, mote.start);
            tl.to(el, { opacity: 0, duration: mote.fadeTime, ease: 'sine.in' }, mote.start + mote.riseTime);
        });
    }

    // ---- text timeline ---------------------------------------------------------------------------
    if (story.blocks.length > 0 || story.badge) {
        tl.to('#scrimTop', { opacity: 1, duration: times.scrimTop.duration, ease: 'power1.out' }, times.scrimTop.at);
        if (times.scrimTop.out) {
            tl.to('#scrimTop', { opacity: 0, duration: times.scrimTop.out.duration }, times.scrimTop.out.at);
        }
    }

    story.blocks.forEach((content, index) => {
        const el = blockEls[index];
        const t = times.blocks[index];
        if (t.headline) {
            tl.fromTo(el.querySelectorAll('.hl .wd'), { y: 14, opacity: 0, filter: 'blur(12px)' }, { y: 0, opacity: 1, filter: 'blur(0px)', duration: t.headline.duration, ease: 'power2.out', stagger: t.headline.each }, t.headline.at);
        }
        if (t.subline) {
            tl.fromTo(el.querySelector('.sub'), { y: 10, opacity: 0 }, { y: 0, opacity: 1, duration: t.subline.duration, ease: 'power2.out' }, t.subline.at);
        }
        if (t.out) {
            const targets = [];
            if (t.headline) {
                el.querySelectorAll('.hl .wd').forEach((word) => targets.push(word));
            }
            if (t.subline) {
                targets.push(el.querySelector('.sub'));
            }
            tl.to(targets, { opacity: 0, filter: 'blur(8px)', duration: t.out.duration, ease: 'power1.in' }, t.out.at);
        }
    });

    if (story.badge && times.badge) {
        tl.to(badge, { opacity: 1, scale: 1, rotation: -8, duration: times.badge.duration, ease: 'back.out(2.2)' }, times.badge.at);
        if (times.badge.out) {
            tl.to(badge, { opacity: 0, scale: 0.8, duration: times.badge.out.duration, ease: 'power1.in' }, times.badge.out.at);
        }
    }

    if (story.ending && times.ending) {
        tl.to('#scrimBottom', { opacity: 1, duration: times.ending.scrimDuration, ease: 'power1.out' }, times.ending.scrimAt);
        tl.fromTo(end, { y: 16, opacity: 0, filter: 'blur(10px)' }, { y: 0, opacity: 1, filter: 'blur(0px)', duration: times.ending.duration, ease: 'power2.out' }, times.ending.at);
    }

    window.__timelines.main = tl;
})();
