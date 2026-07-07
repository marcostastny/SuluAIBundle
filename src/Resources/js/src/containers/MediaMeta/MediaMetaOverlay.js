// @flow
import React from 'react';
import {observer} from 'mobx-react';
import {Dialog} from 'sulu-admin-bundle/components';
import ProgressBar from 'sulu-admin-bundle/components/ProgressBar';
import {translate} from 'sulu-admin-bundle/utils';
import mediaMetaStore from '../../stores/mediaMetaStore';
import styles from './mediaMetaOverlay.scss';

@observer
class MediaMetaOverlay extends React.Component<{}> {
    handleConfirm = () => {
        if (mediaMetaStore.phase === 'confirm') {
            mediaMetaStore.start();

            return;
        }
        mediaMetaStore.close();
    };

    handleCancel = () => {
        if (mediaMetaStore.phase === 'running') {
            mediaMetaStore.cancel();

            return;
        }
        mediaMetaStore.close();
    };

    renderConfirm() {
        const {mode, count, countLoading} = mediaMetaStore;

        if (countLoading) {
            return <p>…</p>;
        }

        if (mode === 'missing' && count === 0) {
            return <p>{translate('sulu_ai.media_meta_nothing_missing')}</p>;
        }

        const key = mode === 'missing'
            ? 'sulu_ai.media_meta_confirm_missing'
            : 'sulu_ai.media_meta_confirm_selected';

        return <p>{translate(key, {count})}</p>;
    }

    renderRunning() {
        const {run} = mediaMetaStore;
        if (!run) {
            return null;
        }
        const done = run.processed + run.skipped + run.failed;
        const total = run.total;

        return (
            <div className={styles.progress}>
                <ProgressBar max={total} value={done} />
                <p>{translate('sulu_ai.media_meta_running', {done, total})}</p>
            </div>
        );
    }

    renderSummary() {
        const {run} = mediaMetaStore;
        if (!run) {
            return null;
        }

        return (
            <div className={styles.summary}>
                <p>
                    {translate('sulu_ai.media_meta_summary', {
                        processed: run.processed,
                        skipped: run.skipped,
                        failed: run.failed,
                    })}
                </p>
                {run.aborted && <p className={styles.warning}>{translate('sulu_ai.media_meta_aborted')}</p>}
                {run.cancelled && !run.aborted && <p>{translate('sulu_ai.media_meta_cancelled')}</p>}
                {run.errors.length > 0 && (
                    <div className={styles.errors}>
                        <strong>{translate('sulu_ai.media_meta_failed_items')}</strong>
                        <ul>
                            {run.errors.map((error, index) => (
                                <li key={index}>#{error.id}: {error.message}</li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        );
    }

    render() {
        const {open, phase, countLoading, count} = mediaMetaStore;
        if (!open) {
            return null;
        }

        const confirmDisabled = phase === 'running'
            || (phase === 'confirm' && (countLoading || count === 0));

        return (
            <Dialog
                cancelText={phase === 'summary' ? undefined : translate('sulu_ai.media_meta_cancel')}
                confirmDisabled={confirmDisabled}
                confirmText={phase === 'confirm'
                    ? translate('sulu_ai.media_meta_start')
                    : translate('sulu_admin.ok')}
                onCancel={phase === 'summary' ? undefined : this.handleCancel}
                onConfirm={this.handleConfirm}
                open={open}
                title={translate('sulu_ai.media_meta_title')}
            >
                <div className={styles.content}>
                    {phase === 'confirm' && this.renderConfirm()}
                    {phase === 'running' && this.renderRunning()}
                    {phase === 'summary' && this.renderSummary()}
                </div>
            </Dialog>
        );
    }
}

export default MediaMetaOverlay;
