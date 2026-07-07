import {asList, restoreMessages, serializableActions} from '../src/utils/sessionMessages';

test('serializableActions drops store and baseline and keeps the rest', () => {
    const actions = [{type: 'proposeEdits', store: {big: 'object'}, baseline: {a: 1}, ops: [{op: 'set'}], summary: 'S'}];
    expect(serializableActions(actions)).toEqual([{type: 'proposeEdits', ops: [{op: 'set'}], summary: 'S'}]);
});

test('serializableActions tolerates missing input', () => {
    expect(serializableActions(undefined)).toEqual([]);
});

test('serializableActions drops circular actions instead of throwing', () => {
    const circular = {type: 'weird'};
    circular.self = circular;
    expect(serializableActions([circular, {type: 'ok'}])).toEqual([{type: 'ok'}]);
});

test('serializableActions handles mobx4-style observable arrays (slice, not Array.isArray)', () => {
    // mobx 4 observable arrays are array-likes with a slice() method whose
    // index properties are NOT enumerable — Object.values() sees nothing.
    const observableArrayLike = {
        slice: () => [{type: 'navigate', store: {x: 1}, targets: [{id: '42'}]}],
    };
    expect(serializableActions(observableArrayLike)).toEqual([{type: 'navigate', targets: [{id: '42'}]}]);
});

test('asList passes arrays and converts numeric-keyed objects', () => {
    expect(asList([1, 2])).toEqual([1, 2]);
    expect(asList({0: 'a', 1: 'b'})).toEqual(['a', 'b']);
    expect(asList(null)).toEqual([]);
});

test('restoreMessages initializes flags and marks actions restored', () => {
    const restored = restoreMessages([
        {role: 'assistant', content: 'Hi', actions: [{type: 'switchTab', tab: 'seo'}]},
    ]);
    expect(restored[0].applied).toBe(false);
    expect(restored[0].discarded).toBe(false);
    expect(restored[0].actions[0].restored).toBe(true);
    expect(restored[0].actions[0].done).toBe(false);
});

test('restoreMessages normalizes queryResult rows from mangled objects', () => {
    const restored = restoreMessages({
        0: {role: 'assistant', content: '', actions: {0: {type: 'queryResult', rows: {0: {0: 'a', 1: 'b'}}, columns: ['x', 'y']}}},
    });
    expect(restored[0].actions[0].rows).toEqual([['a', 'b']]);
});

test('restoreMessages keeps hidden flags', () => {
    const restored = restoreMessages([{role: 'user', content: '[abort]', hidden: true}]);
    expect(restored[0].hidden).toBe(true);
});
