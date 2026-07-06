// @flow
import {AbstractFormToolbarAction} from 'sulu-admin-bundle/views';
import assistantContextStore from '../../stores/assistantContextStore';
import {availableTabs} from '../../utils/assistantTabs';

/**
 * Invisible toolbar action: publishes the open page form's store to the
 * assistant context store so the global AssistantWindow can read the live
 * form data and apply approved edits. Registered per edit-form tab; the
 * "tab" option (from AiSettingAdmin) tells the assistant which one.
 */
export default class AssistantFormBridge extends AbstractFormToolbarAction {
    constructor(resourceFormStore, form, router, locales, options, parentResourceStore) {
        super(resourceFormStore, form, router, locales, options, parentResourceStore);

        const tab = typeof options.tab === 'string' ? options.tab : 'content';

        assistantContextStore.setContext({
            type: 'page',
            tab,
            availableTabs: availableTabs(router.route, tab),
            routeName: router.route ? router.route.name : null,
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
