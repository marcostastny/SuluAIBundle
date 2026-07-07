// @flow
import React from 'react';
import {action} from 'mobx';
import {observer} from 'mobx-react';
import {Icon} from 'sulu-admin-bundle/components';
import {Requester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';
import assistantContextStore from '../../stores/assistantContextStore';
import {publishContinuationMessage} from '../../utils/continuation';
import styles from './publishCard.scss';

@observer
class PublishCard extends React.Component {
    @action handleConfirm = () => {
        const {action: publishAction} = this.props;
        if (publishAction.publishing || publishAction.done) {
            return;
        }
        publishAction.publishing = true;
        publishAction.failed = false;

        // Sulu's page trigger endpoint applies the workflow transition
        // ("publish"/"unpublish") to the last saved state of the page.
        const url = '/admin/api/pages/' + encodeURIComponent(publishAction.id)
            + '?action=' + encodeURIComponent(publishAction.mode)
            + '&locale=' + encodeURIComponent(publishAction.locale)
            + '&webspace=' + encodeURIComponent(publishAction.webspace);

        Requester.post(url, {}).then(action(() => {
            publishAction.publishing = false;
            publishAction.done = true;
            if (publishAction.resume && !publishAction.resumed) {
                publishAction.resumed = true;
                assistantContextStore.scheduleResume(
                    publishContinuationMessage(publishAction.title, publishAction.mode),
                    {}
                );
            }
        })).catch(action(() => {
            publishAction.publishing = false;
            publishAction.failed = true;
        }));
    };

    @action handleCancel = () => {
        this.props.action.cancelled = true;
        assistantContextStore.abortTask();
    };

    render() {
        const {action: publishAction} = this.props;
        const inert = Boolean(publishAction.restored);
        const unpublish = publishAction.mode === 'unpublish';

        return (
            <div className={styles.card}>
                {!!publishAction.message && <div className={styles.summary}>{publishAction.message}</div>}
                <div className={styles.row}>
                    <Icon className={styles.icon} name={unpublish ? 'su-unpublish' : 'su-publish'} />
                    <div className={styles.info}>
                        <div className={styles.pageTitle}>{publishAction.title}</div>
                        <div className={styles.meta}>
                            {translate(unpublish ? 'sulu_ai.assistant_unpublish' : 'sulu_ai.assistant_publish')
                                + ' · ' + publishAction.locale}
                        </div>
                    </div>
                </div>
                {!inert && !publishAction.done && !publishAction.cancelled &&
                    <div className={styles.buttons}>
                        <button
                            className={styles.confirmButton}
                            disabled={publishAction.publishing}
                            onClick={this.handleConfirm}
                            type="button"
                        >
                            {translate(unpublish ? 'sulu_ai.assistant_unpublish' : 'sulu_ai.assistant_publish')}
                        </button>
                        <button className={styles.cancelButton} onClick={this.handleCancel} type="button">
                            {translate('sulu_ai.assistant_cancel')}
                        </button>
                    </div>
                }
                {publishAction.failed &&
                    <div className={styles.error}>{translate('sulu_ai.assistant_publish_failed')}</div>
                }
                {publishAction.done &&
                    <div className={styles.status}>
                        {translate(unpublish ? 'sulu_ai.assistant_unpublished' : 'sulu_ai.assistant_published')}
                    </div>
                }
                {publishAction.cancelled &&
                    <div className={styles.status}>{translate('sulu_ai.assistant_task_aborted')}</div>
                }
                {inert && !publishAction.done &&
                    <div className={styles.status}>{translate('sulu_ai.assistant_expired')}</div>
                }
            </div>
        );
    }
}

export default PublishCard;
