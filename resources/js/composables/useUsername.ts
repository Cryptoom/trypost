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

export const useUsername = () => ({ getUsername, isSameUsername, formatUsername });
