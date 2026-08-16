export const MAX_COLLABORATORS = 3;

/** Keep in sync with InstagramCollaborators::USERNAME_PATTERN. */
export const USERNAME_PATTERN = /^(?!.*\.\.)(?!\.)[A-Za-z0-9._]{1,30}(?<!\.)$/;

export const isValidInstagramUsername = (username?: string): boolean => {
    if (!username) {
        return false;
    }

    return USERNAME_PATTERN.test(username.trim().replace(/^@+/, ''));
};

export const useInstagramCollaborators = () => {
    return { isValidInstagramUsername, MAX_COLLABORATORS };
};
