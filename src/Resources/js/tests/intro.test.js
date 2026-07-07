import {buildIntroKeys, buildSuggestionKeys} from '../src/utils/intro';

test('returns keys only for available capabilities, in stable order', () => {
    expect(buildIntroKeys({editing: true, navigation: true, dataQuery: false, pageCreation: true, images: false}))
        .toEqual([
            'sulu_ai.assistant_intro_editing',
            'sulu_ai.assistant_intro_navigation',
            'sulu_ai.assistant_intro_creation',
        ]);
});

test('empty capabilities yield no keys', () => {
    expect(buildIntroKeys({})).toEqual([]);
    expect(buildIntroKeys(undefined)).toEqual([]);
});

test('data and images map to their keys', () => {
    expect(buildIntroKeys({dataQuery: true, images: true}))
        .toEqual(['sulu_ai.assistant_intro_data', 'sulu_ai.assistant_intro_images']);
});

test('publish capability has an intro line', () => {
    expect(buildIntroKeys({publish: true})).toEqual(['sulu_ai.assistant_intro_publish']);
});

describe('buildSuggestionKeys', () => {
    test('all capabilities with a page context yield every chip, in stable order', () => {
        expect(buildSuggestionKeys(
            {editing: true, publish: true, dataQuery: true, pageCreation: true},
            true
        )).toEqual([
            'sulu_ai.assistant_chip_improve',
            'sulu_ai.assistant_chip_seo',
            'sulu_ai.assistant_chip_publish',
            'sulu_ai.assistant_chip_submissions',
            'sulu_ai.assistant_chip_create',
        ]);
    });

    test('page-bound chips disappear without a page context', () => {
        expect(buildSuggestionKeys(
            {editing: true, publish: true, dataQuery: true, pageCreation: true},
            false
        )).toEqual([
            'sulu_ai.assistant_chip_submissions',
            'sulu_ai.assistant_chip_create',
        ]);
    });

    test('missing capabilities yield no chips', () => {
        expect(buildSuggestionKeys({}, true)).toEqual([]);
        expect(buildSuggestionKeys(undefined, false)).toEqual([]);
    });
});
