import {csvFilename, normalizeRows} from '../src/utils/dataQuery';

test('normalizeRows converts object rows from the Requester transform back to arrays', () => {
    // Sulu's Requester turns nested arrays into {0: ..., 1: ...} objects.
    expect(normalizeRows([{0: 'a', 1: 'b'}, ['c', 'd']])).toEqual([['a', 'b'], ['c', 'd']]);
    expect(normalizeRows([{0: 'x', 1: undefined}])).toEqual([['x', undefined]]);
    expect(normalizeRows(undefined)).toEqual([]);
    expect(normalizeRows([null])).toEqual([[]]);
});

test('builds a safe csv filename from the title', () => {
    expect(csvFilename('Latest reservations')).toBe('latest-reservations.csv');
    expect(csvFilename('Tischreservationen: Juli / 2026')).toBe('tischreservationen-juli-2026.csv');
});

test('falls back for empty or symbol-only titles', () => {
    expect(csvFilename('')).toBe('query-result.csv');
    expect(csvFilename(null)).toBe('query-result.csv');
    expect(csvFilename('***')).toBe('query-result.csv');
});
