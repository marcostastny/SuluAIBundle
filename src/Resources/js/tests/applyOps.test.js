import applyOps, {ApplyConflictError, snapshotBlockTypes} from '../src/utils/applyOps';

function fakeStore(data) {
    return {
        data,
        changes: [],
        change(path, value) {
            this.changes.push([path, value]);
        },
    };
}

describe('applyOps', () => {
    it('applies a scalar set', () => {
        const store = fakeStore({title: 'old'});
        applyOps(store, [{op: 'set', path: '/title', value: 'new'}]);
        expect(store.changes).toEqual([['/title', 'new']]);
    });

    it('applies setBlockField on the matching block', () => {
        const store = fakeStore({blocks: [{type: 'text', text: 'a'}, {type: 'text', text: 'b'}]});
        const baseline = snapshotBlockTypes(store.data, [{op: 'setBlockField', path: '/blocks/1/text', value: 'B'}]);
        applyOps(store, [{op: 'setBlockField', path: '/blocks/1/text', value: 'B'}], baseline);
        expect(store.changes).toEqual([['/blocks', [{type: 'text', text: 'a'}, {type: 'text', text: 'B'}]]]);
    });

    it('throws when the targeted block type changed since the proposal (external edit)', () => {
        // Proposal was made when block 0 was a "quote"; the user swapped it for an "image".
        const store = fakeStore({blocks: [{type: 'image'}]});
        const staleBaseline = {blocks: ['quote']};
        expect(() => applyOps(store, [{op: 'setBlockField', path: '/blocks/0/text', value: 'x'}], staleBaseline))
            .toThrow(ApplyConflictError);
    });

    it('does NOT throw when a structural op precedes setBlockField on the same property', () => {
        // Regression guard: baseline must shift with insert/remove/move so the
        // setBlockField index still lines up with the block it targeted.
        const store = fakeStore({blocks: [{type: 'text', text: 'a'}]});
        const ops = [
            {op: 'insertBlock', path: '/blocks', index: 0, block: {type: 'image'}},
            {op: 'setBlockField', path: '/blocks/1/text', value: 'A'},
        ];
        const baseline = snapshotBlockTypes(store.data, ops);

        expect(() => applyOps(store, ops, baseline)).not.toThrow();
        const written = store.changes.find(([path]) => path === '/blocks')[1];
        expect(written).toEqual([{type: 'image'}, {type: 'text', text: 'A'}]);
    });

    const nestedData = () => ({
        blocks: [
            {type: 'textBlock', text: 'intro'},
            {type: 'infoCards', cards: [
                {type: 'card', head: 'Check-in', rows: [
                    {type: 'row', label: 'Kurtaxe', value: 'CHF 2.50'},
                    {type: 'row', label: 'Check-out', value: '11:00'},
                ]},
            ]},
        ],
    });

    it('applies a nested setBlockField', () => {
        const store = fakeStore(nestedData());
        const ops = [{op: 'setBlockField', path: '/blocks/1/cards/0/rows/0/value', value: 'CHF 3.50'}];
        const baseline = snapshotBlockTypes(store.data, ops);

        applyOps(store, ops, baseline);

        const written = store.changes.find(([path]) => path === '/blocks')[1];
        expect(written[1].cards[0].rows[0].value).toBe('CHF 3.50');
        expect(store.data.blocks[1].cards[0].rows[0].value).toBe('CHF 2.50');
    });

    it('applies nested structural ops with running indices', () => {
        const store = fakeStore(nestedData());
        const ops = [
            {op: 'insertBlock', path: '/blocks/1/cards/0/rows', index: 0, block: {type: 'row', label: 'Hunde', value: 'CHF 10'}},
            {op: 'setBlockField', path: '/blocks/1/cards/0/rows/2/value', value: '12:00'},
            {op: 'removeBlock', path: '/blocks/1/cards/0/rows', index: 1},
        ];
        const baseline = snapshotBlockTypes(store.data, ops);

        applyOps(store, ops, baseline);

        const rows = store.changes.find(([path]) => path === '/blocks')[1][1].cards[0].rows;
        expect(rows.map((row) => row.label)).toEqual(['Hunde', 'Check-out']);
        expect(rows[1].value).toBe('12:00');
    });

    it('throws when a block along the nested path changed type since the proposal', () => {
        const store = fakeStore(nestedData());
        const staleBaseline = {
            'blocks': ['textBlock', 'gallery'],
            'blocks/1/cards': ['card'],
            'blocks/1/cards/0/rows': ['row', 'row'],
        };

        expect(() => applyOps(store, [{op: 'setBlockField', path: '/blocks/1/cards/0/rows/0/value', value: 'x'}], staleBaseline))
            .toThrow(ApplyConflictError);
    });

    it('throws when a nested container no longer exists', () => {
        const store = fakeStore({blocks: [{type: 'textBlock', text: 'intro'}]});

        expect(() => applyOps(store, [{op: 'setBlockField', path: '/blocks/1/cards/0/rows/0/value', value: 'x'}], {}))
            .toThrow(ApplyConflictError);
    });

    it('snapshots types for every container along a nested path', () => {
        const baseline = snapshotBlockTypes(nestedData(), [
            {op: 'setBlockField', path: '/blocks/1/cards/0/rows/0/value', value: 'x'},
        ]);

        expect(baseline).toEqual({
            'blocks': ['textBlock', 'infoCards'],
            'blocks/1/cards': ['card'],
            'blocks/1/cards/0/rows': ['row', 'row'],
        });
    });

    it('shifts the baseline on removeBlock so later setBlockField stays aligned', () => {
        const store = fakeStore({blocks: [{type: 'image'}, {type: 'text', text: 'a'}]});
        const ops = [
            {op: 'removeBlock', path: '/blocks', index: 0},
            {op: 'setBlockField', path: '/blocks/0/text', value: 'A'},
        ];
        const baseline = snapshotBlockTypes(store.data, ops);

        expect(() => applyOps(store, ops, baseline)).not.toThrow();
        const written = store.changes.find(([path]) => path === '/blocks')[1];
        expect(written).toEqual([{type: 'text', text: 'A'}]);
    });
});
