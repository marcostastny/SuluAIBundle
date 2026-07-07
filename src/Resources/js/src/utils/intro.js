// @flow
// Order defines how capabilities appear in the intro message.
const CAPABILITY_KEYS = [
    ['editing', 'sulu_ai.assistant_intro_editing'],
    ['navigation', 'sulu_ai.assistant_intro_navigation'],
    ['pageCreation', 'sulu_ai.assistant_intro_creation'],
    ['publish', 'sulu_ai.assistant_intro_publish'],
    ['dataQuery', 'sulu_ai.assistant_intro_data'],
    ['images', 'sulu_ai.assistant_intro_images'],
];

// Clickable quick-action chips shown under the intro. The translated string
// doubles as the message that is sent when the chip is clicked. Chips whose
// prompt targets "this page" only appear while a page form is open.
const SUGGESTION_KEYS = [
    ['editing', 'sulu_ai.assistant_chip_improve', true],
    ['editing', 'sulu_ai.assistant_chip_seo', true],
    ['publish', 'sulu_ai.assistant_chip_publish', true],
    ['dataQuery', 'sulu_ai.assistant_chip_submissions', false],
    ['pageCreation', 'sulu_ai.assistant_chip_create', false],
];

export const buildIntroKeys = (capabilities: ?Object): Array<string> => {
    return CAPABILITY_KEYS
        .filter(([capability]) => Boolean(capabilities && capabilities[capability]))
        .map(([, key]) => key);
};

export const buildSuggestionKeys = (capabilities: ?Object, hasPageContext: boolean): Array<string> => {
    return SUGGESTION_KEYS
        .filter(([capability, , needsContext]) =>
            Boolean(capabilities && capabilities[capability]) && (!needsContext || hasPageContext)
        )
        .map(([, key]) => key);
};
