export const MAX_COLLABORATORS = 3;

export const normalizeInstagramUsername = (username: string): string => username.trim().replace(/^@+/, '');

export const collaboratorUsernames = (value: unknown): string[] =>
    Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string' && item !== '') : [];

export const formatCollaboratorNames = (value: unknown): string =>
    collaboratorUsernames(value)
        .map((username) => `@${normalizeInstagramUsername(username)}`)
        .join(', ');

export const useInstagramCollaborators = () => {
    return { collaboratorUsernames, formatCollaboratorNames, MAX_COLLABORATORS };
};
