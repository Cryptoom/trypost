import { ref, type Ref } from 'vue';

export const typeInto = async (
    target: Ref<string>,
    text: string,
    cancelled: () => boolean,
): Promise<void> => {
    target.value = '';

    if (text === '') {
        return;
    }

    const step = text.length > 80 ? 3 : 1;

    for (let index = 0; index < text.length; index += step) {
        if (cancelled()) {
            target.value = text;

            return;
        }

        target.value = text.slice(0, Math.min(text.length, index + step));
        await new Promise((resolve) => setTimeout(resolve, 12));
    }

    target.value = text;
};

export const useTypedText = () => {
    const title = ref('');
    const description = ref('');
    const streaming = ref(false);
    let generation = 0;

    const cancel = (): void => {
        generation += 1;
        streaming.value = false;
    };

    const play = async (nextTitle: string, nextDescription: string): Promise<void> => {
        const id = ++generation;
        const cancelled = (): boolean => id !== generation;

        streaming.value = true;
        await typeInto(title, nextTitle, cancelled);

        if (cancelled()) {
            return;
        }

        await typeInto(description, nextDescription, cancelled);

        if (! cancelled()) {
            streaming.value = false;
        }
    };

    const snap = (nextTitle: string, nextDescription: string): void => {
        cancel();
        title.value = nextTitle;
        description.value = nextDescription;
    };

    return { title, description, streaming, play, snap, cancel };
};
