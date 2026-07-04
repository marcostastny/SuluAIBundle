import React from 'react';
import {render} from 'react-dom';
import {initializer, Router} from 'sulu-admin-bundle/services';
import {fieldRegistry} from 'sulu-admin-bundle/containers';
import {formToolbarActionRegistry} from 'sulu-admin-bundle/views';
import {MetaGeneratorToolbarAction, AssistantFormBridge} from './containers';
import AssistantWindow from './containers/Assistant';
import ImageGeneratorOverlay from './containers/ImageGenerator';
import startMediaToolbarButtonObserver from './utils/mediaToolbarButton';
import assistantContextStore from './stores/assistantContextStore';
import routerStore from './stores/routerStore';
import imageGeneratorStore from './stores/imageGeneratorStore';
import {PasswordField} from './fields';

// The admin router is created privately inside startAdmin(), which calls
// addUpdateAttributesHook right after construction - capture the instance
// there so the assistant can navigate from any view.
const originalAddUpdateAttributesHook = Router.prototype.addUpdateAttributesHook;
Router.prototype.addUpdateAttributesHook = function(...args) {
    routerStore.setRouter(this);

    return originalAddUpdateAttributesHook.apply(this, args);
};

initializer.addUpdateConfigHook('sulu_ai_bundle', (config) => {
    assistantContextStore.setAvailable(Boolean(config && config.assistant && config.assistant.available));
    imageGeneratorStore.setConfig(config && config.imageGeneration);
});

initializer.addUpdateConfigHook('sulu_admin', (config, initialized) => {
    if (!initialized) {
        formToolbarActionRegistry.add('sulu_ai.generate_meta', MetaGeneratorToolbarAction);
        formToolbarActionRegistry.add('sulu_ai.assistant', AssistantFormBridge);
        fieldRegistry.add('password', PasswordField);

        const container = document.createElement('div');
        if (document.body) {
            document.body.appendChild(container);
            render(<AssistantWindow />, container);
        }

        const imageContainer = document.createElement('div');
        if (document.body) {
            document.body.appendChild(imageContainer);
            render(<ImageGeneratorOverlay />, imageContainer);
        }
        startMediaToolbarButtonObserver();
    }
});
