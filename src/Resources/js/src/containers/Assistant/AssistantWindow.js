// @flow
import React from 'react';
import {action, observable} from 'mobx';
import {observer} from 'mobx-react';
import {Icon} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import assistantContextStore from '../../stores/assistantContextStore';
import DiffCard from './DiffCard';
import NavigationCard from './NavigationCard';
import styles from './assistantWindow.scss';

@observer
class AssistantWindow extends React.Component {
    @observable inputValue = '';
    messagesEndRef = React.createRef();
    scrollSignature = '';

    componentDidUpdate() {
        // Only scroll on a new message or when the thinking indicator toggles —
        // not on every keystroke (inputValue is an observable read in render()).
        const signature = assistantContextStore.messages.length + ':' + (assistantContextStore.loading ? '1' : '0');
        if (signature === this.scrollSignature) {
            return;
        }
        this.scrollSignature = signature;
        if (this.messagesEndRef.current) {
            this.messagesEndRef.current.scrollIntoView({behavior: 'smooth'});
        }
    }

    handleToggle = () => {
        assistantContextStore.togglePanel();
    };

    @action handleClear = () => {
        assistantContextStore.clearMessages();
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

    handleKeyDown = (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.handleSend();
        }
    };

    renderMessage = (message, index) => {
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
                    <span className={styles.title}>{assistantContextStore.agentName || translate('sulu_ai.assistant')}</span>
                    <button
                        aria-label={translate('sulu_ai.assistant_clear')}
                        className={styles.closeButton}
                        onClick={this.handleClear}
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
                <div className={styles.messages}>
                    {assistantContextStore.messages.map(this.renderMessage)}
                    {assistantContextStore.loading &&
                        <div className={styles.assistantMessage}>{translate('sulu_ai.assistant_thinking')}</div>
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
                    <button
                        className={styles.sendButton}
                        disabled={assistantContextStore.loading}
                        onClick={this.handleSend}
                        type="button"
                    >
                        <Icon name="su-angle-right" />
                    </button>
                </div>
            </div>
        );
    }
}

export default AssistantWindow;
