import { ref } from 'vue';

export const getUsername = (value?: string | null): string =>
    (value ?? '').trim().replaceAll('@', '');

export const isSameUsername = (left?: string | null, right?: string | null): boolean => {
    const username = getUsername(left).toLowerCase();

    return username !== '' && username === getUsername(right).toLowerCase();
};

export const formatUsername = (value?: string | null): string => {
    const username = getUsername(value);

    return username === '' ? '' : `@${username}`;
};

export const useUsername = (
    usernames: () => string[],
    ownUsername: () => string | undefined,
    onChange: (usernames: string[]) => void,
) => {
    const draft = ref('');
    const isSelf = ref(false);

    const add = () => {
        const username = getUsername(draft.value);
        draft.value = '';
        isSelf.value = isSameUsername(username, ownUsername());

        if (username && !isSelf.value) {
            onChange([...usernames(), username]);
        }
    };

    const remove = (username: string) => onChange(usernames().filter((item) => item !== username));

    return { draft, isSelf, add, remove };
};
