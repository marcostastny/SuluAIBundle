// @flow
import React from 'react';
import {action, observable} from 'mobx';
import {observer} from 'mobx-react';
import {translate} from 'sulu-admin-bundle/utils';
import {csvFilename} from '../../utils/dataQuery';
import styles from './queryResultCard.scss';

const EXPORT_ENDPOINT = '/admin/api/ai/assistant/query-export';

@observer
class QueryResultCard extends React.Component {
    @observable exporting = false;
    @observable exportFailed = false;

    @action handleDownload = () => {
        const {action: queryAction} = this.props;
        if (this.exporting) {
            return;
        }
        this.exporting = true;
        this.exportFailed = false;

        fetch(EXPORT_ENDPOINT, {
            body: JSON.stringify({sql: queryAction.sql}),
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            method: 'POST',
        }).then((response) => {
            if (!response.ok) {
                throw new Error('export failed');
            }

            return response.blob();
        }).then(action((blob) => {
            this.exporting = false;
            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = csvFilename(queryAction.title);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
        })).catch(action(() => {
            this.exporting = false;
            this.exportFailed = true;
        }));
    };

    render() {
        const {action: queryAction} = this.props;
        const columns = queryAction.columns || [];
        const rows = queryAction.rows || [];

        return (
            <div className={styles.card}>
                {!!queryAction.title && <div className={styles.title}>{queryAction.title}</div>}
                <div className={styles.tableWrapper}>
                    <table className={styles.table}>
                        <thead>
                            <tr>
                                {columns.map((column, index) => (
                                    <th key={index}>{column}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row, rowIndex) => (
                                <tr key={rowIndex}>
                                    {row.map((value, cellIndex) => (
                                        <td key={cellIndex} title={value === null ? undefined : value}>
                                            {value === null ? '—' : value}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className={styles.footer}>
                    <span className={styles.rowCount}>
                        {translate('sulu_ai.assistant_rows', {count: queryAction.rowCount})}
                    </span>
                    <button
                        className={styles.downloadButton}
                        disabled={this.exporting}
                        onClick={this.handleDownload}
                        type="button"
                    >
                        {translate('sulu_ai.assistant_download_csv')}
                    </button>
                </div>
                {this.exportFailed &&
                    <div className={styles.error}>{translate('sulu_ai.assistant_export_failed')}</div>
                }
            </div>
        );
    }
}

export default QueryResultCard;
