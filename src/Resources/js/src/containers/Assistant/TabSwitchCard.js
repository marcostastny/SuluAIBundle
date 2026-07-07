// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {Icon} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import assistantContextStore from '../../stores/assistantContextStore';
import routerStore from '../../stores/routerStore';
import {tabViewName} from '../../utils/assistantTabs';
import {tabSwitchContinuationMessage} from '../../utils/continuation';
import styles from './tabSwitchCard.scss';

const TAB_LABELS = {
    content: 'sulu_ai.assistant_tab_content',
    seo: 'sulu_ai.assistant_tab_seo',
};

@observer
class TabSwitchCard extends React.Component {
    @observable showSavePrompt = false;

    pushError = () => {
        assistantContextStore.messages.push({
            role: 'error',
            content: translate('sulu_ai.assistant_error'),
            actions: [],
            applied: false,
            discarded: false,
        });
    };

    @action navigateToTab = () => {
        const {action: switchAction} = this.props;
        const context = assistantContextStore.context;
        if (!context || !context.routeName || !context.router) {
            this.pushError();

            return;
        }

        const view = tabViewName(context.routeName, switchAction.tab);
        if (!view || !routerStore.navigate(view, toJS(context.router.attributes))) {
            this.pushError();

            return;
        }

        switchAction.done = true;
        if (switchAction.resume) {
            assistantContextStore.scheduleResume(
                tabSwitchContinuationMessage(switchAction.tab),
                {tab: switchAction.tab}
            );
        }
    };

    @action handleSwitch = () => {
        const context = assistantContextStore.context;
        if (context && context.resourceFormStore && context.resourceFormStore.dirty) {
            // Switching tabs remounts the form view; unsaved changes must be
            // persisted first or Sulu's own dirty dialog blocks the switch.
            this.showSavePrompt = true;

            return;
        }
        this.navigateToTab();
    };

    @action handleSaveAndSwitch = () => {
        const context = assistantContextStore.context;
        this.showSavePrompt = false;
        if (!context || !context.resourceFormStore) {
            this.pushError();

            return;
        }
        context.resourceFormStore.save()
            .then(this.navigateToTab)
            .catch(action(() => {
                this.props.action.cancelled = true;
                this.pushError();
            }));
    };

    @action handleCancel = () => {
        this.props.action.cancelled = true;
        this.showSavePrompt = false;
        assistantContextStore.abortTask();
    };

    render() {
        const {action: switchAction} = this.props;
        const tabLabel = translate(TAB_LABELS[switchAction.tab] || TAB_LABELS.content);

        if (switchAction.restored) {
            // Restored from a persisted session: the form context this switch
            // belonged to is gone, so render it inert.
            return (
                <div className={styles.card}>
                    {!!switchAction.message && <div className={styles.summary}>{switchAction.message}</div>}
                    <div className={styles.status}>{translate('sulu_ai.assistant_expired')}</div>
                </div>
            );
        }

        return (
            <div className={styles.card}>
                {!!switchAction.message && <div className={styles.summary}>{switchAction.message}</div>}
                <div className={styles.row}>
                    <Icon className={styles.icon} name="su-arrows-alt" />
                    <div className={styles.info}>{tabLabel}</div>
                    {!switchAction.done && !switchAction.cancelled && !this.showSavePrompt &&
                        <React.Fragment>
                            <button className={styles.switchButton} onClick={this.handleSwitch} type="button">
                                {translate('sulu_ai.assistant_switch_tab')}
                            </button>
                            <button className={styles.cancelButton} onClick={this.handleCancel} type="button">
                                {translate('sulu_ai.assistant_cancel')}
                            </button>
                        </React.Fragment>
                    }
                </div>
                {this.showSavePrompt && !switchAction.done && !switchAction.cancelled &&
                    <div className={styles.savePrompt}>
                        <div className={styles.savePromptText}>{translate('sulu_ai.assistant_unsaved_prompt')}</div>
                        <div className={styles.buttons}>
                            <button className={styles.switchButton} onClick={this.handleSaveAndSwitch} type="button">
                                {translate('sulu_ai.assistant_save_and_switch')}
                            </button>
                            <button className={styles.cancelButton} onClick={this.handleCancel} type="button">
                                {translate('sulu_ai.assistant_cancel')}
                            </button>
                        </div>
                    </div>
                }
                {switchAction.done && <div className={styles.status}>{translate('sulu_ai.assistant_switched')}</div>}
                {switchAction.cancelled && !switchAction.done &&
                    <div className={styles.status}>{translate('sulu_ai.assistant_task_aborted')}</div>
                }
            </div>
        );
    }
}

export default TabSwitchCard;
