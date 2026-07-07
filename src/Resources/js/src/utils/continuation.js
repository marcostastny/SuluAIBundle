// @flow

// Hard cap on automatic follow-up requests per user instruction, so a
// confused model can never loop forever.
export const MAX_AUTO_CONTINUATIONS = 6;

// The continuation texts are sent as hidden user messages: the model reads
// them (hence English + explicit context notes), the user never sees them.

export function navigationContinuationMessage(target) {
    return '[The user opened "' + (target.title || '') + '" (' + target.type + ', locale ' + target.locale + '). '
        + 'The page context of this message is the newly opened page. Continue the task.]';
}

export function tabSwitchContinuationMessage(tab) {
    return '[The user switched to the "' + tab + '" tab. The context of this message is that tab. Continue the task.]';
}

export function creationContinuationMessage(title) {
    return '[The user created and opened the new page "' + (title || '') + '". '
        + 'The page context of this message is the new page - it has no content yet. Continue the task and propose the content now.]';
}

export function applyContinuationMessage() {
    return '[The user approved and applied the proposed changes. Continue the task.]';
}

export function abortMessage() {
    return '[The user rejected the proposed action and aborted the task. Do not continue it unless asked again.]';
}

/**
 * Does a freshly registered form context satisfy what a pending resume is
 * waiting for? `expected` may pin an id (after navigation) and/or a tab
 * (after a tab switch); ids are compared loosely because router attributes
 * are strings while store ids may be numbers.
 */
export function contextMatchesExpectation(expected, context) {
    if (!context) {
        return false;
    }
    if (expected.id !== undefined && String(context.id) !== String(expected.id)) {
        return false;
    }
    if (expected.tab !== undefined && context.tab !== expected.tab) {
        return false;
    }

    return true;
}
