// @flow
// Order defines how capabilities appear in the intro message.
const CAPABILITY_KEYS = [
    ['editing', 'sulu_ai.assistant_intro_editing'],
    ['navigation', 'sulu_ai.assistant_intro_navigation'],
    ['pageCreation', 'sulu_ai.assistant_intro_creation'],
    ['dataQuery', 'sulu_ai.assistant_intro_data'],
    ['images', 'sulu_ai.assistant_intro_images'],
];

export const buildIntroKeys = (capabilities: ?Object): Array<string> => {
    return CAPABILITY_KEYS
        .filter(([capability]) => Boolean(capabilities && capabilities[capability]))
        .map(([, key]) => key);
};
