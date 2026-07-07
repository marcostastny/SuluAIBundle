// @flow
import {action, observable, toJS, when} from 'mobx';
import {Requester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';
import {snapshotBlockTypes} from '../utils/applyOps';
import {MAX_AUTO_CONTINUATIONS, abortMessage, contextMatchesExpectation} from '../utils/continuation';
import {normalizeRows} from '../utils/dataQuery';
import {asList, restoreMessages, serializableActions} from '../utils/sessionMessages';
import routerStore from './routerStore';

const ENDPOINT = '/admin/api/ai/assistant/chat';
const SESSIONS_ENDPOINT = '/admin/api/ai/assistant/sessions';

class AssistantContextStore {
    @observable.ref context = null;
    @observable messages = [];
    @observable loading = false;
    @observable available = false;
    @observable agentName = '';
    @observable panelOpen = false;
    @observable.ref pendingResume = null;
    @observable sessionId = null;
    @observable sessionTitle = '';
    @observable.ref sessions = [];
    @observable sessionsLoading = false;
    @observable.ref capabilities = {};
    autoContinuations = 0;
    resumeTimeout = null;
    resumeDisposer = null;

    @action setAvailable(available) {
        this.available = available;
    }

    @action setAgentName(agentName) {
        this.agentName = (agentName || '').trim();
    }

    @action togglePanel() {
        this.panelOpen = !this.panelOpen;
    }

    get currentStore() {
        return this.context ? this.context.resourceFormStore : null;
    }

    @action setContext(context) {
        this.context = context;
        this.loading = false;
        routerStore.setRouter(context.router);
        this.tryResume();
    }

    @action clearContext() {
        this.context = null;
        this.loading = false;
    }

    @action clearMessages() {
        this.messages = [];
        this.autoContinuations = 0;
        this.cancelPendingResume();
    }

    @action setCapabilities(capabilities) {
        this.capabilities = capabilities || {};
    }

    @action startNewSession() {
        this.clearMessages();
        this.sessionId = null;
        this.sessionTitle = '';
    }

    @action loadSessions() {
        this.sessionsLoading = true;

        return Requester.get(SESSIONS_ENDPOINT).then(action((response) => {
            this.sessionsLoading = false;
            this.sessions = asList(response.sessions);
        })).catch(action(() => {
            this.sessionsLoading = false;
            this.sessions = [];
        }));
    }

    @action openSession(id) {
        if (this.loading) {
            return Promise.resolve();
        }

        return Requester.get(SESSIONS_ENDPOINT + '/' + id).then(action((response) => {
            this.cancelPendingResume();
            this.autoContinuations = 0;
            this.sessionId = response.id;
            this.sessionTitle = response.title || '';
            this.messages = restoreMessages(response.messages);
        })).catch(action(() => {
            this.pushNotice(translate('sulu_ai.assistant_error'));
        }));
    }

    @action deleteSession(id) {
        return Requester.delete(SESSIONS_ENDPOINT + '/' + id).then(action(() => {
            this.sessions = this.sessions.filter((session) => session.id !== id);
            if (this.sessionId === id) {
                this.startNewSession();
            }
        })).catch(action(() => {
            this.pushNotice(translate('sulu_ai.assistant_error'));
        }));
    }

    @action pushNotice(content) {
        this.messages.push({role: 'assistant', content, actions: [], applied: false, discarded: false});
    }

    @action cancelPendingResume() {
        this.pendingResume = null;
        if (this.resumeTimeout) {
            clearTimeout(this.resumeTimeout);
            this.resumeTimeout = null;
        }
        if (this.resumeDisposer) {
            this.resumeDisposer();
            this.resumeDisposer = null;
        }
    }

    /**
     * Arms the multiturn loop: once a form context matching `expected` is
     * registered and done loading, a hidden continuation message re-invokes
     * the assistant so it can finish the task the user just approved a step
     * of.
     */
    @action scheduleResume(note, expected = {}) {
        if (this.autoContinuations >= MAX_AUTO_CONTINUATIONS) {
            this.pushNotice(translate('sulu_ai.assistant_task_limit'));

            return;
        }
        this.cancelPendingResume();
        this.pendingResume = {note, expected};
        this.resumeTimeout = setTimeout(action(() => {
            if (this.pendingResume) {
                this.cancelPendingResume();
                this.pushNotice(translate('sulu_ai.assistant_task_timeout'));
            }
        }), 20000);
        this.tryResume();
    }

    @action tryResume() {
        const pending = this.pendingResume;
        const context = this.context;
        if (!pending || !context || !context.resourceFormStore) {
            return;
        }
        const contextInfo = {id: context.resourceFormStore.id, tab: context.tab || 'content'};
        if (!contextMatchesExpectation(pending.expected, contextInfo)) {
            return;
        }

        const store = context.resourceFormStore;
        const fire = action(() => {
            if (this.pendingResume !== pending) {
                return;
            }
            this.cancelPendingResume();
            this.autoContinuations += 1;
            this.sendMessage(pending.note, {hidden: true});
        });

        if (!store.loading) {
            fire();

            return;
        }
        // The new form is still fetching its data; continue once it is usable.
        this.resumeDisposer = when(() => !store.loading, fire);
    }

    /**
     * The user rejected a step of a multi-step task: stop the loop and leave
     * a hidden marker so the model does not resume the task on the next
     * question.
     */
    @action abortTask() {
        this.cancelPendingResume();
        this.messages.push({
            role: 'user',
            content: abortMessage(),
            hidden: true,
            actions: [],
            applied: false,
            discarded: false,
        });
        this.pushNotice(translate('sulu_ai.assistant_task_aborted'));
    }

    @action sendMessage(text, options = {}) {
        const hidden = Boolean(options.hidden);
        if (this.loading || !text.trim()) {
            return Promise.resolve();
        }
        if (!hidden) {
            // A fresh user instruction supersedes any half-finished task.
            this.cancelPendingResume();
            this.autoContinuations = 0;
        }

        const context = this.context;
        const resourceFormStore = context ? context.resourceFormStore : null;
        const formData = resourceFormStore ? toJS(resourceFormStore.data) : {};
        // Bind the proposal to this exact form-store instance and capture the
        // block layout now, so an approved edit can only be applied to the page
        // it was generated against (the store instance changes on navigation).
        const store = resourceFormStore;

        this.messages.push({role: 'user', content: text.trim(), hidden, actions: [], applied: false, discarded: false});
        this.loading = true;

        const history = this.messages
            .filter((message) => message.role === 'user' || message.role === 'assistant')
            .map((message) => ({
                role: message.role,
                content: message.content,
                hidden: Boolean(message.hidden),
                actions: serializableActions(message.actions),
            }));

        return Requester.post(ENDPOINT, {
            sessionId: this.sessionId,
            context: context && resourceFormStore ? {
                type: context.type,
                id: resourceFormStore.id,
                locale: resourceFormStore.locale ? resourceFormStore.locale.get() : undefined,
                template: formData.template,
                webspace: context.router.attributes.webspace,
                tab: context.tab || 'content',
                availableTabs: context.availableTabs || [context.tab || 'content'],
            } : null,
            formData,
            messages: history,
        }).then(action((response) => {
            this.loading = false;
            const actions = (response.actions || []).map((responseAction) => {
                // Initialize the per-card status flags now: mobx 4 only
                // observes keys that exist when the object becomes observable,
                // so flags added later would never re-render the cards.
                const base = {...responseAction, opened: false, resumed: false, done: false, cancelled: false, restored: false};

                if (responseAction.type === 'proposeEdits') {
                    return {
                        ...base,
                        store,
                        baseline: snapshotBlockTypes(formData, responseAction.ops || []),
                    };
                }
                if (responseAction.type === 'queryResult') {
                    // The Requester response transform turns the row arrays
                    // into numeric-keyed objects — restore real arrays.
                    return {...base, rows: normalizeRows(responseAction.rows)};
                }
                if (responseAction.type === 'createPage') {
                    return {...base, creating: false, failed: false};
                }

                return base;
            });
            this.messages.push({
                role: 'assistant',
                content: response.reply || '',
                actions,
                applied: false,
                discarded: false,
            });
            if (response.sessionId) {
                this.sessionId = response.sessionId;
            }
            if (response.sessionTitle) {
                this.sessionTitle = response.sessionTitle;
            }
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
