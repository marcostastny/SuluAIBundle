// @flow
import React from 'react';
import {action} from 'mobx';
import {observer} from 'mobx-react';
import {Icon} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import assistantContextStore from '../../stores/assistantContextStore';
import routerStore from '../../stores/routerStore';
import styles from './navigationCard.scss';

const TYPE_ICONS = {
    pages: 'su-document',
    snippets: 'su-snippet',
    articles: 'su-newspaper',
    forms: 'su-magic',
};

const TYPE_LABELS = {
    pages: 'sulu_ai.assistant_type_page',
    snippets: 'sulu_ai.assistant_type_snippet',
    articles: 'sulu_ai.assistant_type_article',
    forms: 'sulu_ai.assistant_type_form',
};

@observer
class NavigationCard extends React.Component {
    @action handleOpen = (target) => {
        if (!routerStore.navigate(target.view, target.attributes)) {
            assistantContextStore.messages.push({
                role: 'error',
                content: translate('sulu_ai.assistant_error'),
                actions: [],
                applied: false,
                discarded: false,
            });

            return;
        }

        this.props.message.applied = true;
    };

    render() {
        const {action: navigateAction, message} = this.props;

        return (
            <div className={styles.card}>
                {!!navigateAction.message && <div className={styles.summary}>{navigateAction.message}</div>}
                {navigateAction.targets.map((target, index) => (
                    <div className={styles.row} key={index}>
                        <Icon className={styles.icon} name={TYPE_ICONS[target.type] || 'su-document'} />
                        <div className={styles.info}>
                            <div className={styles.targetTitle}>{target.title}</div>
                            <div className={styles.meta}>
                                {translate(TYPE_LABELS[target.type] || TYPE_LABELS.pages)}
                                {target.locale ? ' · ' + target.locale : ''}
                            </div>
                        </div>
                        <button
                            className={styles.openButton}
                            onClick={() => this.handleOpen(target)}
                            type="button"
                        >
                            {translate('sulu_ai.assistant_open')}
                        </button>
                    </div>
                ))}
                {message.applied && <div className={styles.status}>{translate('sulu_ai.assistant_opened')}</div>}
            </div>
        );
    }
}

export default NavigationCard;
