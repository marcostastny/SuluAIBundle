// @flow
import React from 'react';
import {action, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {translate} from 'sulu-admin-bundle/utils';
import assistantContextStore from '../../stores/assistantContextStore';
import applyOps, {ApplyConflictError} from '../../utils/applyOps';
import {applyContinuationMessage} from '../../utils/continuation';
import styles from './diffCard.scss';

// Property names may contain slashes ("seo/title"); the corresponding form
// data is nested (data.seo.title), mirroring Sulu's JSON-pointer semantics.
const valueAt = (data, property) =>
    property.split('/').reduce(
        (value, segment) => (value && typeof value === 'object' ? value[segment] : undefined),
        data
    );

// "/blocks/3/cards/0/rows/5/value" → "blocks #3 · cards #0 · rows #5 · value"
const blockPathLabel = (path) => {
    const segments = path.replace(/^\//, '').split('/');
    const parts = [];
    for (let i = 0; i < segments.length; i += 2) {
        parts.push(i + 1 < segments.length ? segments[i] + ' #' + segments[i + 1] : segments[i]);
    }

    return parts.join(' · ');
};

// Walks a (possibly nested) block container path through the form data.
const blocksAtPath = (data, segments) => {
    let blocks = data[segments[0]];
    for (let i = 1; i < segments.length; i += 2) {
        const block = Array.isArray(blocks) ? blocks[parseInt(segments[i])] : undefined;
        blocks = block && typeof block === 'object' ? block[segments[i + 1]] : undefined;
    }

    return Array.isArray(blocks) ? blocks : [];
};

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
            if (proposeAction.resume) {
                // Same page, same tab — the context already matches, so this
                // fires the continuation immediately.
                assistantContextStore.scheduleResume(applyContinuationMessage(), {});
            }
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
        if (this.props.action.resume) {
            assistantContextStore.abortTask();
        }
    };

    renderOpRow = (op, index, data) => {
        switch (op.op) {
            case 'set': {
                const property = op.path.slice(1);

                return (
                    <div className={styles.row} key={index}>
                        <div className={styles.rowLabel}>{property}</div>
                        <div className={styles.oldValue}>{formatValue(valueAt(data, property))}</div>
                        <div className={styles.newValue}>{formatValue(op.value)}</div>
                    </div>
                );
            }
            case 'setBlockField': {
                const segments = op.path.replace(/^\//, '').split('/');
                const field = segments[segments.length - 1];
                const blockIndex = parseInt(segments[segments.length - 2]);
                const block = blocksAtPath(data, segments.slice(0, -2))[blockIndex] || {};

                return (
                    <div className={styles.row} key={index}>
                        <div className={styles.rowLabel}>{blockPathLabel(op.path)}</div>
                        <div className={styles.oldValue}>{formatValue(block[field])}</div>
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
                const blocks = blocksAtPath(data, op.path.replace(/^\//, '').split('/'));
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

        if (proposeAction.restored) {
            // Restored from a persisted session: the baseline and form store
            // are gone, so the diff can neither render nor apply.
            return (
                <div className={styles.card}>
                    {!!proposeAction.summary && <div className={styles.summary}>{proposeAction.summary}</div>}
                    <div className={styles.status}>{translate('sulu_ai.assistant_expired')}</div>
                </div>
            );
        }

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
