import { onBeforeUnmount, onMounted, type Ref } from 'vue';

const documentHeight = (): number =>
    Math.max(document.documentElement.scrollHeight, document.body.scrollHeight);

const prefersReducedMotion = (): boolean =>
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const behaviour = (smooth: boolean): ScrollBehavior =>
    smooth && !prefersReducedMotion() ? 'smooth' : 'auto';

/**
 * The element that actually scrolls the thread, or null when the window does.
 *
 * The app shell is `h-svh overflow-hidden`, so under the sidebar layout the
 * window never scrolls and the thread lives inside an `overflow-y-auto`
 * ancestor. Onboarding renders outside that shell and scrolls the window.
 * Asking the DOM which one it is keeps both working without either page
 * having to declare it.
 */
const scrollingAncestor = (element: HTMLElement | null): HTMLElement | null => {
    let node = element?.parentElement ?? null;

    while (node !== null && node !== document.body) {
        const overflowY = window.getComputedStyle(node).overflowY;

        if (overflowY === 'auto' || overflowY === 'scroll') {
            return node;
        }

        node = node.parentElement;
    }

    return null;
};

export const scrollWindowToBottom = (smooth = false): void => {
    if (typeof window === 'undefined') {
        return;
    }

    window.scrollTo({
        top: documentHeight(),
        left: 0,
        behavior: behaviour(smooth),
    });
};

/**
 * Chat pin-to-bottom: whenever the thread grows (new bubble, image, chips, or
 * a tool card revealing its next step), the view jumps to the end. Same rule
 * for every step.
 */
export const useChatScroll = (root: Ref<HTMLElement | null>) => {
    let observer: ResizeObserver | null = null;

    const scrollToBottom = (smooth = false): void => {
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                const scroller = scrollingAncestor(root.value);

                if (scroller === null) {
                    scrollWindowToBottom(smooth);

                    return;
                }

                scroller.scrollTo({
                    top: scroller.scrollHeight,
                    left: 0,
                    behavior: behaviour(smooth),
                });
            });
        });
    };

    onMounted(() => {
        if (root.value === null || typeof ResizeObserver === 'undefined') {
            return;
        }

        observer = new ResizeObserver(() => {
            scrollToBottom();
        });
        observer.observe(root.value);
        scrollToBottom();
    });

    onBeforeUnmount(() => {
        observer?.disconnect();
    });

    return { scrollToBottom };
};
