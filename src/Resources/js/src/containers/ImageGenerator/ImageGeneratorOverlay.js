// @flow
import React from 'react';
import {action, computed, observable} from 'mobx';
import {observer} from 'mobx-react';
import {Checkbox, Divider, FileUploadButton, Form, Overlay, SingleSelect} from 'sulu-admin-bundle/components';
import TextArea from 'sulu-admin-bundle/components/TextArea';
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

    @computed get maxCount() {
        const models = imageGeneratorStore.models;
        const selected = this.selectedModelIds
            .map((id) => models.find((candidate) => candidate.id === id))
            .filter(Boolean);

        if (selected.length === 0) {
            return 4;
        }

        return Math.max(1, Math.min(4, ...selected.map((model) => model.maxImages || 1)));
    }

    @computed get canGenerate() {
        return this.prompt.trim().length > 0 && this.selectedModelIds.length > 0 && !this.generating;
    }

    @action handlePromptChange = (value) => {
        this.prompt = value || '';
    };

    @action handleStyleChange = (value) => {
        this.style = value;
    };

    @action handleFormatChange = (value) => {
        this.format = value;
    };

    @action handleResolutionChange = (value) => {
        this.resolution = value;
    };

    @action handlePurposeChange = (value) => {
        this.purpose = value;
    };

    @action handleCountChange = (value) => {
        this.count = value;
    };

    @action toggleModel = (checked, value) => {
        if (checked) {
            this.selectedModelIds = [...this.selectedModelIds, value];
        } else {
            this.selectedModelIds = this.selectedModelIds.filter((candidate) => candidate !== value);
        }
        if (!this.referencesAllowed) {
            this.references = [];
        }
        if (this.count > this.maxCount) {
            this.count = this.maxCount;
        }
    };

    @action handleReferenceUpload = (file) => {
        const reader = new FileReader();
        reader.onload = action(() => {
            const result = String(reader.result || '');
            const base64 = result.substring(result.indexOf(',') + 1);
            this.references = [
                ...this.references,
                {filename: file.name, contentType: file.type || 'image/png', data: base64},
            ];
        });
        reader.readAsDataURL(file);
    };

    @action removeReference = (index) => {
        this.references = this.references.filter((reference, current) => current !== index);
    };

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

    renderOptions(keys, prefix, translationMap) {
        return keys.map((key) => (
            <SingleSelect.Option key={key} value={key}>
                {translate(prefix + (translationMap ? translationMap[key] : key))}
            </SingleSelect.Option>
        ));
    }

    render() {
        const summary = [
            translate('sulu_ai.image_style_' + this.style),
            translate('sulu_ai.image_format_' + FORMAT_TRANSLATION[this.format]),
            translate('sulu_ai.image_resolution_' + this.resolution),
            translate('sulu_ai.image_purpose_' + this.purpose),
        ].join(' · ');

        if (!imageGeneratorStore.available) {
            return null;
        }

        const countKeys = [];
        for (let value = 1; value <= this.maxCount; value++) {
            countKeys.push(value);
        }

        return (
            <Overlay
                confirmDisabled={!this.canGenerate}
                confirmLoading={this.generating}
                confirmText={translate('sulu_ai.image_generate_button')}
                onClose={this.close}
                onConfirm={this.handleGenerate}
                open={imageGeneratorStore.open}
                size="small"
                title={translate('sulu_ai.image_generator_title')}
            >
                <div className={styles.body}>
                <Form>
                    <Form.Field colSpan={12} label={translate('sulu_ai.image_prompt')} required={true}>
                        <TextArea
                            onChange={this.handlePromptChange}
                            placeholder={translate('sulu_ai.image_prompt_placeholder')}
                            rows={4}
                            value={this.prompt}
                        />
                        <div className={styles.summary}>{summary}</div>
                    </Form.Field>
                    <Form.Field colSpan={6} label={translate('sulu_ai.image_style')}>
                        <SingleSelect onChange={this.handleStyleChange} value={this.style}>
                            {this.renderOptions(STYLE_KEYS, 'sulu_ai.image_style_')}
                        </SingleSelect>
                    </Form.Field>
                    <Form.Field colSpan={6} label={translate('sulu_ai.image_format')}>
                        <SingleSelect onChange={this.handleFormatChange} value={this.format}>
                            {this.renderOptions(FORMAT_KEYS, 'sulu_ai.image_format_', FORMAT_TRANSLATION)}
                        </SingleSelect>
                    </Form.Field>
                    <Form.Field colSpan={6} label={translate('sulu_ai.image_resolution')}>
                        <SingleSelect onChange={this.handleResolutionChange} value={this.resolution}>
                            {this.renderOptions(RESOLUTION_KEYS, 'sulu_ai.image_resolution_')}
                        </SingleSelect>
                    </Form.Field>
                    <Form.Field colSpan={6} label={translate('sulu_ai.image_purpose')}>
                        <SingleSelect onChange={this.handlePurposeChange} value={this.purpose}>
                            {this.renderOptions(PURPOSE_KEYS, 'sulu_ai.image_purpose_')}
                        </SingleSelect>
                    </Form.Field>
                    <Form.Field
                        colSpan={12}
                        description={translate('sulu_ai.image_models_hint')}
                        label={translate('sulu_ai.image_models_label')}
                    >
                        <div className={styles.models}>
                            {imageGeneratorStore.models.map((model) => (
                                <Checkbox
                                    checked={this.selectedModelIds.includes(model.id)}
                                    key={model.id}
                                    onChange={this.toggleModel}
                                    value={model.id}
                                >
                                    {model.label}
                                </Checkbox>
                            ))}
                        </div>
                    </Form.Field>
                    <Form.Field colSpan={6} label={translate('sulu_ai.image_count')}>
                        <div className={styles.count}>
                            <SingleSelect onChange={this.handleCountChange} value={this.count}>
                                {countKeys.map((value) => (
                                    <SingleSelect.Option key={value} value={value}>
                                        {String(value)}
                                    </SingleSelect.Option>
                                ))}
                            </SingleSelect>
                        </div>
                    </Form.Field>
                    <Form.Field
                        colSpan={12}
                        description={this.referencesAllowed
                            ? undefined
                            : translate('sulu_ai.image_references_unsupported')}
                        label={translate('sulu_ai.image_references')}
                    >
                        {this.referencesAllowed &&
                            <div className={styles.references}>
                                <FileUploadButton accept="image/*" icon="su-image" onUpload={this.handleReferenceUpload}>
                                    {translate('sulu_ai.image_references_drop')}
                                </FileUploadButton>
                                <ul className={styles.referenceList}>
                                    {this.references.map((reference, index) => (
                                        <li className={styles.referenceChip} key={index}>
                                            {reference.filename}
                                            <button
                                                className={styles.referenceRemove}
                                                onClick={() => this.removeReference(index)}
                                                type="button"
                                            >
                                                ✕
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        }
                    </Form.Field>
                </Form>

                {this.resultGroups.length > 0 &&
                    <div>
                        <Divider />
                        {this.resultGroups.map((group) => (
                            <div className={styles.resultGroup} key={group.id}>
                                <h3 className={styles.resultGroupTitle}>{group.label}</h3>
                                {group.error && <div className={styles.error}>{group.error}</div>}
                                <div className={styles.results}>
                                    {group.images.map((image) => (
                                        <div className={styles.resultCard} key={image.id}>
                                            <img alt={image.title} src={image.thumbnailUrl} />
                                        </div>
                                    ))}
                                </div>
                                {!group.loading && !group.error && group.images.length > 0 &&
                                    <p className={styles.savedHint}>{translate('sulu_ai.image_saved_hint')}</p>
                                }
                            </div>
                        ))}
                    </div>
                }
                </div>
            </Overlay>
        );
    }
}

export default ImageGeneratorOverlay;
