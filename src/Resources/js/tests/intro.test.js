import {buildIntroKeys} from '../src/utils/intro';

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
