import { ref, watch } from 'vue';

/** Mirrors `InstagramCollaborators::USERNAME_PATTERN` after a leading `@` is stripped. */
const USERNAME_PATTERN = /^(?!.*\.\.)(?!\.)[A-Za-z0-9._]{1,30}(?<!\.)$/;

export const getUsername = (value?: string | null): string =>
    (value ?? '').trim().replace(/^@+/, '');

export const isValidUsername = (value?: string | null): boolean => USERNAME_PATTERN.test(getUsername(value));

export const isSameUsername = (left?: string | null, right?: string | null): boolean => {
    const username = getUsername(left).toLowerCase();

    return username !== '' && username === getUsername(right).toLowerCase();
};

export const formatUsername = (value?: string | null): string => {
    const username = getUsername(value);

    return username === '' ? '' : `@${username}`;
};

/**
 * Chip input backing a list of usernames. `rejection` names the server error key
 * that would come back, so the caller translates it with the same message.
 */
export const useUsername = (
    usernames: () => string[],
    ownUsername: () => string | undefined | null,
    onChange: (usernames: string[]) => void,
    max: number,
) => {
    const draft = ref('');
    const rejection = ref<'self' | 'duplicate' | 'max' | 'invalid' | null>(null);

    watch(draft, () => {
        rejection.value = null;
    });

    const add = () => {
        const username = getUsername(draft.value);
        const current = usernames();

        if (username === '') {
            rejection.value = null;

            return;
        }

        rejection.value = !isValidUsername(username)
            ? 'invalid'
            : isSameUsername(username, ownUsername())
              ? 'self'
              : current.some((item) => isSameUsername(item, username))
                ? 'duplicate'
                : current.length >= max
                  ? 'max'
                  : null;

        if (rejection.value) {
            return;
        }

        draft.value = '';
        onChange([...current, username]);
    };

    const remove = (username: string) => onChange(usernames().filter((item) => item !== username));

    return { draft, rejection, add, remove };
};
