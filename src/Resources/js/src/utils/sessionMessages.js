// @flow
import {normalizeRows} from './dataQuery';

// Sulu's Requester response transform turns nested arrays into numeric-keyed
// objects — treat both shapes as lists everywhere below.
export const asList = (value: any): Array<any> => {
    if (Array.isArray(value)) {
        return value;
    }
    if (value && typeof value === 'object') {
        return Object.values(value);
    }

    return [];
};

/**
 * Strips client-only fields from action objects so the history sent to (and
 * persisted by) the backend is plain JSON. The JSON round-trip also detaches
 * mobx observables and drops anything not serializable.
 */
export const serializableActions = (actions: any): Array<Object> => {
    return asList(actions)
        .map((action) => {
            if (!action || typeof action !== 'object') {
                return null;
            }
            const {store: _store, baseline: _baseline, ...rest} = action;
            try {
                return JSON.parse(JSON.stringify(rest));
            } catch (error) {
                return null;
            }
        })
        .filter(Boolean);
};

/**
 * Turns persisted session messages back into store messages: every per-card
 * status flag is initialized here (mobx 4 only observes keys that exist when
 * the object becomes observable) and actions are marked restored so cards
 * render inert.
 */
export const restoreMessages = (messages: any): Array<Object> => {
    return asList(messages).map((message) => ({
        role: String((message && message.role) || 'assistant'),
        content: String((message && message.content) || ''),
        hidden: Boolean(message && message.hidden),
        applied: false,
        discarded: false,
        actions: asList(message && message.actions).map((action) => {
            const base = {
                ...action,
                restored: true,
                opened: false,
                resumed: false,
                done: false,
                cancelled: false,
                creating: false,
                failed: false,
            };
            if (action && action.type === 'queryResult') {
                return {...base, rows: normalizeRows(asList(action.rows))};
            }

            return base;
        }),
    }));
};
