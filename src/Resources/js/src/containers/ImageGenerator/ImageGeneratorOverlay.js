// @flow
import React from 'react';
import {action, computed, observable} from 'mobx';
import {observer} from 'mobx-react';
import {Requester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';
import imageGeneratorStore from '../../stores/imageGeneratorStore';
import styles from './imageGeneratorOverlay.scss';

const ENDPOINT = '/admin/api/ai/image/generate';
const STYLE_KEYS = ['photorealistic', 'illustration', '3d', 'watercolor', 'minimal', 'product'];
const FORMAT_KEYS = ['1:1', '16:9', '9:16', '4:3', '3:2'];
const FORMAT_TRANSLATION = {'1:1': '1_1', '16:9': '16_9', '9:16': '9_16', '4:3': '4_3', '3:2': '3_2'};
const RESOLUTION_KEYS = ['standard', 'high'];
const PURPOSE_KEYS = ['web', 'social', 'print', 'presentation'];

@observer
class ImageGeneratorOverlay extends React.Component<{}> {
    @observable prompt = '';
    @observable style = 'photorealistic';
    @observable format = '16:9';
    @observable resolution = 'high';
    @observable purpose = 'web';
    @observable count = 1;
    @observable selectedModelIds = [];
    @observable references = [];
    @observable generating = false;
    @observable resultGroups = [];

    @computed get referencesAllowed() {
        const models = imageGeneratorStore.models;
        if (this.selectedModelIds.length === 0) {
            return false;
        }

        return this.selectedModelIds.every((id) => {
            const model = models.find((candidate) => candidate.id === id);

            return Boolean(model && model.supportsReference);
        });
    }

    @computed get canGenerate() {
        return this.prompt.trim().length > 0 && this.selectedModelIds.length > 0 && !this.generating;
    }

    @action handlePromptChange = (event) => {
        this.prompt = event.currentTarget.value;
    };

    @action handleSelectChange = (field) => (event) => {
        this[field] = event.currentTarget.value;
    };

    @action handleCount = (value) => {
        this.count = value;
    };

    @action toggleModel = (id) => {
        if (this.selectedModelIds.includes(id)) {
            this.selectedModelIds = this.selectedModelIds.filter((candidate) => candidate !== id);
        } else {
            this.selectedModelIds = [...this.selectedModelIds, id];
        }
        if (!this.referencesAllowed) {
            this.references = [];
        }
    };

    @action handleReferenceFiles = (fileList) => {
        const files = Array.from(fileList || []);
        Promise.all(files.map((file) => this.readFile(file))).then(action((encoded) => {
            this.references = encoded;
        }));
    };

    readFile(file) {
        return new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = () => {
                const result = String(reader.result || '');
                const base64 = result.substring(result.indexOf(',') + 1);
                resolve({filename: file.name, contentType: file.type || 'image/png', data: base64});
            };
            reader.readAsDataURL(file);
        });
    }

    @action close = () => {
        imageGeneratorStore.close();
        this.resultGroups = [];
        this.references = [];
    };

    @action handleGenerate = () => {
        if (!this.canGenerate) {
            return;
        }
        this.generating = true;
        this.resultGroups = imageGeneratorStore.models
            .filter((model) => this.selectedModelIds.includes(model.id))
            .map((model) => ({label: model.label, id: model.id, images: [], error: null, loading: true}));

        const context = imageGeneratorStore.context || {locale: 'en', collectionId: null};
        const payloadBase = {
            prompt: this.prompt,
            style: this.style,
            purpose: this.purpose,
            format: this.format,
            resolution: this.resolution,
            count: this.count,
            locale: context.locale,
            references: this.referencesAllowed ? this.references : [],
        };

        const requests = this.resultGroups.map((group) =>
            Requester.post(ENDPOINT, {...payloadBase, modelId: group.id})
                .then(action((response) => {
                    group.images = response.images || [];
                    group.loading = false;
                }))
                .catch(action(() => {
                    group.error = translate('sulu_ai.image_error');
                    group.loading = false;
                }))
        );

        Promise.all(requests).then(action(() => {
            this.generating = false;
        }));
    };

    renderOption(prefix, key) {
        return <option key={key} value={key}>{translate(prefix + this.optionSuffix(prefix, key))}</option>;
    }

    optionSuffix(prefix, key) {
        if (prefix === 'sulu_ai.image_format_') {
            return FORMAT_TRANSLATION[key];
        }

        return key;
    }

    render() {
        if (!imageGeneratorStore.open) {
            return null;
        }

        const summary = [
            translate('sulu_ai.image_style_' + this.style),
            translate('sulu_ai.image_format_' + FORMAT_TRANSLATION[this.format]),
            translate('sulu_ai.image_resolution_' + this.resolution),
            translate('sulu_ai.image_purpose_' + this.purpose),
        ].join(' · ');

        return (
            <div className={styles.overlay}>
                <div className={styles.panel}>
                    <button className={styles.closeButton} onClick={this.close} type="button">✕</button>
                    <h2 className={styles.title}>{translate('sulu_ai.image_generator_title')}</h2>

                    <label className={styles.label}>{translate('sulu_ai.image_prompt')}</label>
                    <textarea
                        className={styles.textarea}
                        onChange={this.handlePromptChange}
                        placeholder={translate('sulu_ai.image_prompt_placeholder')}
                        value={this.prompt}
                    />
                    <p className={styles.summary}>{summary}</p>

                    <label className={styles.label}>{translate('sulu_ai.image_style')}</label>
                    <select className={styles.select} onChange={this.handleSelectChange('style')} value={this.style}>
                        {STYLE_KEYS.map((key) => this.renderOption('sulu_ai.image_style_', key))}
                    </select>

                    <label className={styles.label}>{translate('sulu_ai.image_format')}</label>
                    <select className={styles.select} onChange={this.handleSelectChange('format')} value={this.format}>
                        {FORMAT_KEYS.map((key) => this.renderOption('sulu_ai.image_format_', key))}
                    </select>

                    <label className={styles.label}>{translate('sulu_ai.image_resolution')}</label>
                    <select
                        className={styles.select}
                        onChange={this.handleSelectChange('resolution')}
                        value={this.resolution}
                    >
                        {RESOLUTION_KEYS.map((key) => this.renderOption('sulu_ai.image_resolution_', key))}
                    </select>

                    <label className={styles.label}>{translate('sulu_ai.image_purpose')}</label>
                    <select
                        className={styles.select}
                        onChange={this.handleSelectChange('purpose')}
                        value={this.purpose}
                    >
                        {PURPOSE_KEYS.map((key) => this.renderOption('sulu_ai.image_purpose_', key))}
                    </select>

                    <label className={styles.label}>{translate('sulu_ai.image_models_label')}</label>
                    <p className={styles.hint}>{translate('sulu_ai.image_models_hint')}</p>
                    <div className={styles.modelRow}>
                        {imageGeneratorStore.models.map((model) => (
                            <label key={model.id}>
                                <input
                                    checked={this.selectedModelIds.includes(model.id)}
                                    onChange={() => this.toggleModel(model.id)}
                                    type="checkbox"
                                />
                                {' '}{model.label}
                            </label>
                        ))}
                    </div>

                    <label className={styles.label}>{translate('sulu_ai.image_count')}</label>
                    <div className={styles.countRow}>
                        {[1, 2, 3, 4].map((value) => (
                            <button
                                className={value === this.count ? styles.countButtonActive : styles.countButton}
                                key={value}
                                onClick={() => this.handleCount(value)}
                                type="button"
                            >
                                {value}
                            </button>
                        ))}
                    </div>

                    <label className={styles.label}>{translate('sulu_ai.image_references')}</label>
                    <div
                        className={this.referencesAllowed
                            ? styles.dropzone
                            : styles.dropzone + ' ' + styles.dropzoneDisabled}
                    >
                        {this.referencesAllowed
                            ? (
                                <input
                                    accept="image/*"
                                    multiple
                                    onChange={(event) => this.handleReferenceFiles(event.currentTarget.files)}
                                    type="file"
                                />
                            )
                            : translate('sulu_ai.image_references_unsupported')
                        }
                    </div>

                    <button
                        className={styles.generateButton}
                        disabled={!this.canGenerate}
                        onClick={this.handleGenerate}
                        type="button"
                    >
                        {this.generating
                            ? translate('sulu_ai.image_generating')
                            : translate('sulu_ai.image_generate_button')}
                    </button>

                    {this.resultGroups.map((group) => (
                        <div key={group.id}>
                            <label className={styles.label}>{group.label}</label>
                            <div className={styles.results}>
                                {group.error && <div className={styles.resultError}>{group.error}</div>}
                                {group.images.map((image) => (
                                    <div className={styles.resultCard} key={image.id}>
                                        <img alt={image.title} src={image.thumbnailUrl} />
                                    </div>
                                ))}
                            </div>
                            {!group.loading && !group.error && group.images.length > 0 &&
                                <p className={styles.hint}>{translate('sulu_ai.image_saved_hint')}</p>
                            }
                        </div>
                    ))}
                </div>
            </div>
        );
    }
}

export default ImageGeneratorOverlay;
