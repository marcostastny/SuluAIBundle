// @flow

/**
 * Filename for the CSV download of a query-result card, derived from the
 * card title the model provided.
 */
export const csvFilename = (title: ?string): string => {
    const base = (title || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    return (base || 'query-result') + '.csv';
};
