// @flow
import React from 'react';
import {action, observable} from 'mobx';
import {observer} from 'mobx-react';
import {Icon} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import assistantContextStore from '../../stores/assistantContextStore';
import {buildIntroKeys, buildSuggestionKeys} from '../../utils/intro';
import DiffCard from './DiffCard';
import NavigationCard from './NavigationCard';
import TabSwitchCard from './TabSwitchCard';
import QueryResultCard from './QueryResultCard';
import CreationCard from './CreationCard';
import PublishCard from './PublishCard';
import styles from './assistantWindow.scss';

// Server tools whose execution is announced via stream status events.
const STATUS_KEYS = {
    'search_content': 'sulu_ai.assistant_status_search_content',
    'list_data_tables': 'sulu_ai.assistant_status_list_data_tables',
    'run_select_query': 'sulu_ai.assistant_status_run_select_query',
    'resolve_url': 'sulu_ai.assistant_status_resolve_url',
};

@observer
class AssistantWindow extends React.Component {
    @observable inputValue = '';
    messagesEndRef = React.createRef();
    scrollSignature = '';

    componentDidUpdate() {
        // Only scroll on a new message, indicator toggle, or stream progress —
        // not on every keystroke (inputValue is an observable read in render()).
        const signature = assistantContextStore.messages.length
            + ':' + (assistantContextStore.loading ? '1' : '0')
            + ':' + assistantContextStore.streamText.length
            + ':' + (assistantContextStore.streamStatus || '');
        if (signature === this.scrollSignature) {
            return;
        }
        this.scrollSignature = signature;
        if (this.messagesEndRef.current) {
            this.messagesEndRef.current.scrollIntoView({behavior: 'smooth'});
        }
    }

    @observable historyOpen = false;

    handleToggle = () => {
        assistantContextStore.togglePanel();
    };

    @action handleHistoryToggle = () => {
        this.historyOpen = !this.historyOpen;
        if (this.historyOpen) {
            assistantContextStore.loadSessions();
        }
    };

    @action handleNewSession = () => {
        assistantContextStore.startNewSession();
        this.historyOpen = false;
    };

    @action handleOpenSession = (id) => {
        assistantContextStore.openSession(id);
        this.historyOpen = false;
    };

    handleDeleteSession = (event, id) => {
        event.stopPropagation();
        assistantContextStore.deleteSession(id);
    };

    @action handleInputChange = (event) => {
        this.inputValue = event.currentTarget.value;
    };

    @action handleSend = () => {
        const text = this.inputValue;
        if (!text.trim() || assistantContextStore.loading) {
            return;
        }
        this.inputValue = '';
        assistantContextStore.sendMessage(text);
    };

    handleStop = () => {
        assistantContextStore.stopStreaming();
    };

    handleKeyDown = (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.handleSend();
        }
    };

    renderStatusLabel() {
        const status = assistantContextStore.streamStatus;

        return translate((status && STATUS_KEYS[status]) || 'sulu_ai.assistant_thinking');
    }

    handleSuggestion = (key) => {
        assistantContextStore.sendMessage(translate(key));
    };

    renderIntro() {
        const keys = buildIntroKeys(assistantContextStore.capabilities);
        const suggestionKeys = buildSuggestionKeys(
            assistantContextStore.capabilities,
            Boolean(assistantContextStore.context)
        );
        const name = assistantContextStore.agentName;

        return (
            <div className={styles.messageRow}>
                <div className={styles.assistantMessage}>
                    <div>
                        {name
                            ? translate('sulu_ai.assistant_intro_greeting_named', {name})
                            : translate('sulu_ai.assistant_intro_greeting')}
                    </div>
                    {keys.length > 0 &&
                        <ul className={styles.introList}>
                            {keys.map((key) => <li key={key}>{translate(key)}</li>)}
                        </ul>
                    }
                </div>
                {suggestionKeys.length > 0 &&
                    <div className={styles.chips}>
                        {suggestionKeys.map((key) => (
                            <button
                                className={styles.chip}
                                key={key}
                                onClick={() => this.handleSuggestion(key)}
                                type="button"
                            >
                                {translate(key)}
                            </button>
                        ))}
                    </div>
                }
            </div>
        );
    }

    renderMessage = (message, index) => {
        if (message.hidden) {
            return null;
        }

        const bubbleClass = message.role === 'user'
            ? styles.userMessage
            : message.role === 'error' ? styles.errorMessage : styles.assistantMessage;

        return (
            <div className={styles.messageRow} key={index}>
                {!!message.content && <div className={bubbleClass}>{message.content}</div>}
                {(message.actions || [])
                    .filter((messageAction) => messageAction.type === 'proposeEdits')
                    .map((messageAction, actionIndex) => (
                        <DiffCard action={messageAction} key={actionIndex} message={message} />
                    ))
                }
                {(message.actions || [])
                    .filter((messageAction) => messageAction.type === 'navigate')
                    .map((messageAction, actionIndex) => (
                        <NavigationCard action={messageAction} key={'nav-' + actionIndex} message={message} />
                    ))
                }
                {(message.actions || [])
                    .filter((messageAction) => messageAction.type === 'switchTab')
                    .map((messageAction, actionIndex) => (
                        <TabSwitchCard action={messageAction} key={'tab-' + actionIndex} message={message} />
                    ))
                }
                {(message.actions || [])
                    .filter((messageAction) => messageAction.type === 'queryResult')
                    .map((messageAction, actionIndex) => (
                        <QueryResultCard action={messageAction} key={'query-' + actionIndex} message={message} />
                    ))
                }
                {(message.actions || [])
                    .filter((messageAction) => messageAction.type === 'createPage')
                    .map((messageAction, actionIndex) => (
                        <CreationCard action={messageAction} key={'create-' + actionIndex} message={message} />
                    ))
                }
                {(message.actions || [])
                    .filter((messageAction) => messageAction.type === 'publishPage')
                    .map((messageAction, actionIndex) => (
                        <PublishCard action={messageAction} key={'publish-' + actionIndex} message={message} />
                    ))
                }
            </div>
        );
    };

    render() {
        if (!assistantContextStore.available || !assistantContextStore.panelOpen) {
            return null;
        }

        return (
            <div className={styles.panel}>
                <div className={styles.header}>
                    <div className={styles.titleBlock}>
                        <span className={styles.title}>{assistantContextStore.agentName || translate('sulu_ai.assistant')}</span>
                        {!!assistantContextStore.sessionTitle &&
                            <span className={styles.subtitle}>{assistantContextStore.sessionTitle}</span>
                        }
                    </div>
                    <button
                        aria-label={translate('sulu_ai.assistant_sessions')}
                        className={styles.closeButton}
                        onClick={this.handleHistoryToggle}
                        type="button"
                    >
                        <Icon name="su-clock" />
                    </button>
                    <button
                        aria-label={translate('sulu_ai.assistant_new_session')}
                        className={styles.closeButton}
                        onClick={this.handleNewSession}
                        type="button"
                    >
                        <Icon name="su-trash-alt" />
                    </button>
                    <button
                        aria-label={translate('sulu_admin.close')}
                        className={styles.closeButton}
                        onClick={this.handleToggle}
                        type="button"
                    >
                        <Icon name="su-times" />
                    </button>
                </div>
                {this.historyOpen &&
                    <div className={styles.sessionsOverlay}>
                        <button className={styles.newSessionButton} onClick={this.handleNewSession} type="button">
                            {translate('sulu_ai.assistant_new_session')}
                        </button>
                        {assistantContextStore.sessionsLoading &&
                            <div className={styles.sessionsEmpty}>{translate('sulu_ai.assistant_thinking')}</div>
                        }
                        {!assistantContextStore.sessionsLoading && assistantContextStore.sessions.length === 0 &&
                            <div className={styles.sessionsEmpty}>{translate('sulu_ai.assistant_no_sessions')}</div>
                        }
                        {!assistantContextStore.sessionsLoading && assistantContextStore.sessions.map((session) => (
                            <div
                                className={styles.sessionRow}
                                key={session.id}
                                onClick={() => this.handleOpenSession(session.id)}
                                role="button"
                            >
                                <div className={styles.sessionInfo}>
                                    <div className={styles.sessionTitle}>
                                        {session.title || translate('sulu_ai.assistant_new_session')}
                                    </div>
                                    <div className={styles.sessionDate}>
                                        {new Date(session.changed).toLocaleDateString()}
                                    </div>
                                </div>
                                <button
                                    aria-label={translate('sulu_admin.delete')}
                                    className={styles.sessionDeleteButton}
                                    onClick={(event) => this.handleDeleteSession(event, session.id)}
                                    type="button"
                                >
                                    <Icon name="su-trash-alt" />
                                </button>
                            </div>
                        ))}
                    </div>
                }
                <div className={styles.messages}>
                    {assistantContextStore.messages.filter((message) => !message.hidden).length === 0 &&
                        !assistantContextStore.loading &&
                        this.renderIntro()
                    }
                    {assistantContextStore.messages.map(this.renderMessage)}
                    {assistantContextStore.loading && !!assistantContextStore.streamText &&
                        <div className={styles.messageRow}>
                            <div className={styles.assistantMessage}>{assistantContextStore.streamText}</div>
                        </div>
                    }
                    {assistantContextStore.loading && !assistantContextStore.streamText &&
                        <div className={styles.assistantMessage}>{this.renderStatusLabel()}</div>
                    }
                    <div ref={this.messagesEndRef} />
                </div>
                <div className={styles.inputRow}>
                    <textarea
                        className={styles.input}
                        onChange={this.handleInputChange}
                        onKeyDown={this.handleKeyDown}
                        placeholder={translate('sulu_ai.assistant_placeholder')}
                        rows={2}
                        value={this.inputValue}
                    />
                    {assistantContextStore.loading
                        ? <button
                            aria-label={translate('sulu_ai.assistant_stop')}
                            className={styles.sendButton}
                            onClick={this.handleStop}
                            type="button"
                        >
                            <Icon name="su-square" />
                        </button>
                        : <button
                            className={styles.sendButton}
                            onClick={this.handleSend}
                            type="button"
                        >
                            <Icon name="su-angle-right" />
                        </button>
                    }
                </div>
            </div>
        );
    }
}

export default AssistantWindow;
