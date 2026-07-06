// @flow
import React from 'react';
import {action, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {translate} from 'sulu-admin-bundle/utils';
import assistantContextStore from '../../stores/assistantContextStore';
import applyOps, {ApplyConflictError} from '../../utils/applyOps';
import styles from './diffCard.scss';

const formatValue = (value) => {
    if (value === undefined || value === null || value === '') {
        return '—';
    }
    if (typeof value === 'string') {
        return value;
    }

    return JSON.stringify(value, null, 2);
};

@observer
class DiffCard extends React.Component {
    pushConflict = () => {
        this.props.message.discarded = true;
        assistantContextStore.messages.push({
            role: 'error',
            content: translate('sulu_ai.assistant_conflict'),
            actions: [],
            applied: false,
            discarded: false,
        });
    };

    @action handleApply = () => {
        const {action: proposeAction, message} = this.props;
        const context = assistantContextStore.context;
        if (!context) {
            return;
        }

        // Refuse to apply a proposal made against a different form — the chat
        // (and its diff cards) survive navigation between pages, and each form
        // gets a fresh store instance.
        if (proposeAction.store && proposeAction.store !== context.resourceFormStore) {
            this.pushConflict();

            return;
        }

        try {
            applyOps(context.resourceFormStore, proposeAction.ops, proposeAction.baseline || {});
            message.applied = true;
        } catch (error) {
            if (error instanceof ApplyConflictError) {
                this.pushConflict();

                return;
            }
            throw error;
        }
    };

    @action handleDiscard = () => {
        this.props.message.discarded = true;
    };

    renderOpRow = (op, index, data) => {
        switch (op.op) {
            case 'set': {
                const property = op.path.slice(1);

                return (
                    <div className={styles.row} key={index}>
                        <div className={styles.rowLabel}>{property}</div>
                        <div className={styles.oldValue}>{formatValue(data[property])}</div>
                        <div className={styles.newValue}>{formatValue(op.value)}</div>
                    </div>
                );
            }
            case 'setBlockField': {
                const segments = op.path.split('/');
                const blocks = data[segments[1]] || [];
                const block = blocks[parseInt(segments[2])] || {};

                return (
                    <div className={styles.row} key={index}>
                        <div className={styles.rowLabel}>{segments[1] + ' #' + segments[2] + ' · ' + segments[3]}</div>
                        <div className={styles.oldValue}>{formatValue(block[segments[3]])}</div>
                        <div className={styles.newValue}>{formatValue(op.value)}</div>
                    </div>
                );
            }
            case 'insertBlock':
                return (
                    <div className={styles.row} key={index}>
                        <div className={styles.rowLabel}>
                            {'+ ' + op.block.type + ' @ ' + op.path.slice(1) + '[' + op.index + ']'}
                        </div>
                        <div className={styles.newValue}>{formatValue(op.block)}</div>
                    </div>
                );
            case 'removeBlock': {
                const blocks = data[op.path.slice(1)] || [];
                const block = blocks[op.index] || {};

                return (
                    <div className={styles.row} key={index}>
                        <div className={styles.rowLabel}>
                            {'− ' + String(block.type || 'block') + ' @ ' + op.path.slice(1) + '[' + op.index + ']'}
                        </div>
                        <div className={styles.oldValue}>{formatValue(block)}</div>
                    </div>
                );
            }
            case 'moveBlock':
                return (
                    <div className={styles.row} key={index}>
                        <div className={styles.rowLabel}>
                            {'↕ ' + op.path.slice(1) + '[' + op.from + '] → [' + op.to + ']'}
                        </div>
                    </div>
                );
            default:
                return null;
        }
    };

    render() {
        const {action: proposeAction, message} = this.props;
        const context = assistantContextStore.context;
        const data = context ? toJS(context.resourceFormStore.data) : {};

        return (
            <div className={styles.card}>
                {!!proposeAction.summary && <div className={styles.summary}>{proposeAction.summary}</div>}
                {proposeAction.ops.map((op, index) => this.renderOpRow(op, index, data))}
                {message.applied &&
                    <div className={styles.status}>{translate('sulu_ai.assistant_applied')}</div>
                }
                {message.discarded && !message.applied &&
                    <div className={styles.status}>{translate('sulu_ai.assistant_discarded')}</div>
                }
                {!message.applied && !message.discarded &&
                    <div className={styles.buttons}>
                        <button className={styles.applyButton} onClick={this.handleApply} type="button">
                            {translate('sulu_ai.assistant_apply')}
                        </button>
                        <button className={styles.discardButton} onClick={this.handleDiscard} type="button">
                            {translate('sulu_ai.assistant_discard')}
                        </button>
                    </div>
                }
            </div>
        );
    }
}

export default DiffCard;
