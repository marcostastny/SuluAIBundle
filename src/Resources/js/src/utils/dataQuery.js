// @flow

/**
 * Sulu's Requester transforms response data recursively and turns nested
 * arrays into plain objects with numeric keys ({0: ..., 1: ...}) — and null
 * values into undefined. Convert queryResult rows back into real arrays so
 * the table can map over them. Object.values keeps integer-like keys in
 * ascending order.
 */
export const normalizeRows = (rows: any): Array<Array<mixed>> => {
    if (!rows) {
        return [];
    }

    return Array.from(rows).map((row) => {
        if (Array.isArray(row)) {
            return row;
        }
        if (row && typeof row === 'object') {
            return Object.values(row);
        }

        return [];
    });
};

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
