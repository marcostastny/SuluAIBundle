// @flow
import {AbstractFormToolbarAction} from 'sulu-admin-bundle/views';
import assistantContextStore from '../../stores/assistantContextStore';

/**
 * Invisible toolbar action: publishes the open page form's store to the
 * assistant context store so the global AssistantWindow can read the live
 * form data and apply approved edits.
 */
export default class AssistantFormBridge extends AbstractFormToolbarAction {
    constructor(resourceFormStore, form, router, locales, options, parentResourceStore) {
        super(resourceFormStore, form, router, locales, options, parentResourceStore);

        assistantContextStore.setContext({
            type: 'page',
            resourceFormStore,
            router,
        });
    }

    getToolbarItemConfig() {
        return null;
    }

    destroy() {
        assistantContextStore.clearContext();
    }
}
