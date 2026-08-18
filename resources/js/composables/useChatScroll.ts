import { onBeforeUnmount, onMounted, type Ref } from 'vue';

const documentHeight = (): number =>
    Math.max(document.documentElement.scrollHeight, document.body.scrollHeight);

const prefersReducedMotion = (): boolean =>
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export const scrollWindowToBottom = (smooth = false): void => {
    if (typeof window === 'undefined') {
        return;
    }

    window.scrollTo({
        top: documentHeight(),
        left: 0,
        behavior: smooth && !prefersReducedMotion() ? 'smooth' : 'auto',
    });
};

/**
 * Chat pin-to-bottom: whenever the thread grows (new bubble, image, chips),
 * the page jumps to the end. Same rule for every step.
 */
export const useChatScroll = (root: Ref<HTMLElement | null>) => {
    let observer: ResizeObserver | null = null;

    const scrollToBottom = (smooth = false): void => {
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                scrollWindowToBottom(smooth);
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
