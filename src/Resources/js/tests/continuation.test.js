import {
    MAX_AUTO_CONTINUATIONS,
    abortMessage,
    applyContinuationMessage,
    contextMatchesExpectation,
    navigationContinuationMessage,
    tabSwitchContinuationMessage,
} from '../src/utils/continuation';

describe('continuation messages', () => {
    it('describes an opened navigation target', () => {
        const message = navigationContinuationMessage({title: 'Zimmer', type: 'pages', locale: 'de'});
        expect(message).toContain('Zimmer');
        expect(message).toContain('Continue the task');
    });

    it('describes a tab switch', () => {
        expect(tabSwitchContinuationMessage('seo')).toContain('"seo" tab');
    });

    it('describes an applied proposal and an abort', () => {
        expect(applyContinuationMessage()).toContain('applied');
        expect(abortMessage()).toContain('aborted');
    });

    it('caps automatic continuations', () => {
        expect(MAX_AUTO_CONTINUATIONS).toBe(6);
    });
});

describe('contextMatchesExpectation', () => {
    it('requires the expected id and tab when given', () => {
        expect(contextMatchesExpectation({id: '42'}, {id: 42, tab: 'content'})).toBe(true);
        expect(contextMatchesExpectation({id: '42'}, {id: '7', tab: 'content'})).toBe(false);
        expect(contextMatchesExpectation({tab: 'seo'}, {id: '42', tab: 'seo'})).toBe(true);
        expect(contextMatchesExpectation({tab: 'seo'}, {id: '42', tab: 'content'})).toBe(false);
    });

    it('matches any context when nothing specific is expected', () => {
        expect(contextMatchesExpectation({}, {id: '42', tab: 'content'})).toBe(true);
    });

    it('never matches a missing context', () => {
        expect(contextMatchesExpectation({}, null)).toBe(false);
    });
});
