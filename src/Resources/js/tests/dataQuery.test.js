import {csvFilename} from '../src/utils/dataQuery';

test('builds a safe csv filename from the title', () => {
    expect(csvFilename('Latest reservations')).toBe('latest-reservations.csv');
    expect(csvFilename('Tischreservationen: Juli / 2026')).toBe('tischreservationen-juli-2026.csv');
});

test('falls back for empty or symbol-only titles', () => {
    expect(csvFilename('')).toBe('query-result.csv');
    expect(csvFilename(null)).toBe('query-result.csv');
    expect(csvFilename('***')).toBe('query-result.csv');
});
