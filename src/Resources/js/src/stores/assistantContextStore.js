// @flow
import {action, observable, toJS} from 'mobx';
import {Requester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';
import routerStore from './routerStore';

const ENDPOINT = '/admin/api/ai/assistant/chat';

class AssistantContextStore {
    @observable.ref context = null;
    @observable messages = [];
    @observable loading = false;
    @observable available = false;

    @action setAvailable(available) {
        this.available = available;
    }

    @action setContext(context) {
        this.context = context;
        this.loading = false;
        routerStore.setRouter(context.router);
    }

    @action clearContext() {
        this.context = null;
        this.loading = false;
    }

    @action clearMessages() {
        this.messages = [];
    }

    @action sendMessage(text) {
        if (this.loading || !text.trim()) {
            return Promise.resolve();
        }

        const context = this.context;
        const resourceFormStore = context ? context.resourceFormStore : null;
        const formData = resourceFormStore ? toJS(resourceFormStore.data) : {};

        this.messages.push({role: 'user', content: text.trim(), actions: [], applied: false, discarded: false});
        this.loading = true;

        const history = this.messages
            .filter((message) => message.role === 'user' || message.role === 'assistant')
            .map((message) => ({role: message.role, content: message.content}));

        return Requester.post(ENDPOINT, {
            context: context && resourceFormStore ? {
                type: context.type,
                id: resourceFormStore.id,
                locale: resourceFormStore.locale ? resourceFormStore.locale.get() : undefined,
                template: formData.template,
                webspace: context.router.attributes.webspace,
            } : null,
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
