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
