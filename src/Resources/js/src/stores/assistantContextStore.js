// @flow
import {action, observable} from 'mobx';

class AssistantContextStore {
    @observable.ref context = null;
    @observable messages = [];
    @observable loading = false;
    contextKey = null;

    @action setContext(context) {
        const resourceFormStore = context.resourceFormStore;
        const locale = resourceFormStore.locale ? resourceFormStore.locale.get() : '';
        const key = context.type + '-' + String(resourceFormStore.id) + '-' + String(locale);

        // Keep the chat history when the same page in the same locale is re-opened
        // (e.g. after switching tabs and coming back).
        if (key !== this.contextKey) {
            this.messages = [];
        }

        this.contextKey = key;
        this.context = context;
        this.loading = false;
    }

    @action clearContext() {
        this.context = null;
        this.loading = false;
    }
}

export default new AssistantContextStore();
