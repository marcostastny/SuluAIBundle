// @flow
import {action, computed, observable, toJS} from 'mobx';
import {Requester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';
import {snapshotBlockTypes} from '../utils/applyOps';
import routerStore from './routerStore';

const ENDPOINT = '/admin/api/ai/assistant/chat';

/**
 * Identifies the page a proposal was made against, so a diff card cannot be
 * applied after the user navigates to a different page/locale.
 */
export function buildContextKey(context) {
    if (!context || !context.resourceFormStore) {
        return null;
    }
    const store = context.resourceFormStore;
    const locale = store.locale ? store.locale.get() : '';
    const webspace = context.router && context.router.attributes ? context.router.attributes.webspace : '';

    return [context.type, store.id, locale, webspace].join('|');
}

class AssistantContextStore {
    @observable.ref context = null;
    @observable messages = [];
    @observable loading = false;
    @observable available = false;
    @observable panelOpen = false;

    @action setAvailable(available) {
        this.available = available;
    }

    @action togglePanel() {
        this.panelOpen = !this.panelOpen;
    }

    @computed get currentContextKey() {
        return buildContextKey(this.context);
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
        // Capture the page identity and block layout now, so an approved
        // proposal is bound to the page and blocks it was generated against.
        const contextKey = buildContextKey(context);

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
            const actions = (response.actions || []).map((responseAction) =>
                responseAction.type === 'proposeEdits'
                    ? {
                        ...responseAction,
                        contextKey,
                        baseline: snapshotBlockTypes(formData, responseAction.ops || []),
                    }
                    : responseAction
            );
            this.messages.push({
                role: 'assistant',
                content: response.reply || '',
                actions,
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
