// @flow
import {action, observable, toJS} from 'mobx';
import {Requester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';

const ENDPOINT = '/admin/api/ai/assistant/chat';

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

    @action sendMessage(text) {
        const context = this.context;
        if (!context || this.loading || !text.trim()) {
            return Promise.resolve();
        }

        const {resourceFormStore, router} = context;
        const formData = toJS(resourceFormStore.data);

        this.messages.push({role: 'user', content: text.trim(), actions: [], applied: false, discarded: false});
        this.loading = true;

        const history = this.messages
            .filter((message) => message.role === 'user' || message.role === 'assistant')
            .map((message) => ({role: message.role, content: message.content}));

        return Requester.post(ENDPOINT, {
            context: {
                type: context.type,
                id: resourceFormStore.id,
                locale: resourceFormStore.locale ? resourceFormStore.locale.get() : undefined,
                template: formData.template,
                webspace: router.attributes.webspace,
            },
            formData,
            messages: history,
        }).then(action((response) => {
            this.loading = false;
            this.messages.push({
                role: 'assistant',
                content: response.reply || '',
                actions: response.actions || [],
                applied: false,
                discarded: false,
            });
        })).catch(action(() => {
            this.loading = false;
            this.messages.push({
                role: 'error',
                content: translate('sulu_ai.assistant_error'),
                actions: [],
                applied: false,
                discarded: false,
            });
        }));
    }
}

export default new AssistantContextStore();
