// @flow
import React from 'react';
import {action, observable} from 'mobx';
import {AbstractFormToolbarAction} from 'sulu-admin-bundle/views';
import {Requester} from 'sulu-admin-bundle/services';
import {Dialog} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';

const ENDPOINT = '/admin/api/ai/generate-meta';

export default class MetaGeneratorToolbarAction extends AbstractFormToolbarAction {
    @observable loading = false;
    @observable errorMessage = null;

    getNode() {
        return (
            <Dialog
                cancelText={translate('sulu_admin.cancel')}
                confirmText={translate('sulu_admin.ok')}
                key="sulu_ai.generate_meta_error"
                onCancel={this.handleErrorClose}
                onConfirm={this.handleErrorClose}
                open={!!this.errorMessage}
                title={translate('sulu_ai.generate_meta')}
            >
                {this.errorMessage}
            </Dialog>
        );
    }

    getToolbarItemConfig() {
        const id = this.resourceFormStore.id;

        return {
            type: 'button',
            label: translate('sulu_ai.generate_meta'),
            icon: 'su-magic',
            loading: this.loading,
            disabled: !id,
            onClick: this.handleClick,
        };
    }

    @action handleErrorClose = () => {
        this.errorMessage = null;
    };

    @action handleClick = () => {
        const id = this.resourceFormStore.id;
        const locale = this.resourceFormStore.locale ? this.resourceFormStore.locale.get() : undefined;

        if (!id) {
            this.errorMessage = translate('sulu_ai.generate_meta_save_first');
            return;
        }

        this.loading = true;

        Requester.post(ENDPOINT, {id, locale})
            .then(action((response) => {
                this.loading = false;
                if (response.title) {
                    this.resourceFormStore.change('/seo/title', response.title);
                }
                if (response.description) {
                    this.resourceFormStore.change('/seo/description', response.description);
                }
                if (response.keywords) {
                    this.resourceFormStore.change('/seo/keywords', response.keywords);
                }
            }))
            .catch(action(() => {
                this.loading = false;
                this.errorMessage = translate('sulu_ai.generate_meta_error');
            }));
    };
}
