// @flow
import React from 'react';
import {action} from 'mobx';
import {observer} from 'mobx-react';
import {Icon} from 'sulu-admin-bundle/components';
import {Requester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';
import assistantContextStore from '../../stores/assistantContextStore';
import routerStore from '../../stores/routerStore';
import {creationContinuationMessage} from '../../utils/continuation';
import styles from './creationCard.scss';

@observer
class CreationCard extends React.Component {
    @action handleCreate = () => {
        const {action: createAction} = this.props;
        if (createAction.creating || createAction.done) {
            return;
        }
        createAction.creating = true;
        createAction.failed = false;

        const url = '/admin/api/pages'
            + '?webspace=' + encodeURIComponent(createAction.webspace)
            + '&parentId=' + encodeURIComponent(createAction.parentId)
            + '&locale=' + encodeURIComponent(createAction.locale);

        Requester.post(url, {
            locale: createAction.locale,
            template: createAction.template,
            title: createAction.title,
            url: createAction.url,
        }).then(action((response) => {
            createAction.creating = false;
            createAction.done = true;
            routerStore.navigate('sulu_page.page_edit_form', {
                webspace: createAction.webspace,
                locale: createAction.locale,
                id: response.id,
            });
            if (createAction.resume && !createAction.resumed) {
                createAction.resumed = true;
                assistantContextStore.scheduleResume(
                    creationContinuationMessage(createAction.title),
                    {id: response.id}
                );
            }
        })).catch(action(() => {
            createAction.creating = false;
            createAction.failed = true;
        }));
    };

    @action handleCancel = () => {
        this.props.action.cancelled = true;
        assistantContextStore.abortTask();
    };

    render() {
        const {action: createAction} = this.props;
        const inert = Boolean(createAction.restored);
        const parentLabel = createAction.parentId === 'homepage'
            ? translate('sulu_ai.assistant_parent_homepage')
            : createAction.parentTitle;

        return (
            <div className={styles.card}>
                {!!createAction.message && <div className={styles.summary}>{createAction.message}</div>}
                <div className={styles.row}>
                    <Icon className={styles.icon} name="su-document" />
                    <div className={styles.info}>
                        <div className={styles.pageTitle}>{createAction.title}</div>
                        <div className={styles.meta}>
                            {(createAction.templateTitle || createAction.template) + ' · ' + createAction.url + ' · ' + createAction.locale}
                        </div>
                        <div className={styles.meta}>
                            {translate('sulu_ai.assistant_parent') + ': ' + parentLabel}
                        </div>
                    </div>
                </div>
                {!inert && !createAction.done && !createAction.cancelled &&
                    <div className={styles.buttons}>
                        <button
                            className={styles.createButton}
                            disabled={createAction.creating}
                            onClick={this.handleCreate}
                            type="button"
                        >
                            {translate('sulu_ai.assistant_create')}
                        </button>
                        <button className={styles.cancelButton} onClick={this.handleCancel} type="button">
                            {translate('sulu_ai.assistant_cancel')}
                        </button>
                    </div>
                }
                {createAction.failed &&
                    <div className={styles.error}>{translate('sulu_ai.assistant_create_failed')}</div>
                }
                {createAction.done && <div className={styles.status}>{translate('sulu_ai.assistant_created')}</div>}
                {createAction.cancelled &&
                    <div className={styles.status}>{translate('sulu_ai.assistant_task_aborted')}</div>
                }
                {inert && !createAction.done &&
                    <div className={styles.status}>{translate('sulu_ai.assistant_expired')}</div>
                }
            </div>
        );
    }
}

export default CreationCard;
