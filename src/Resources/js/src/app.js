import React from 'react';
import {render} from 'react-dom';
import {initializer, Router} from 'sulu-admin-bundle/services';
import {fieldRegistry} from 'sulu-admin-bundle/containers';
import {formToolbarActionRegistry} from 'sulu-admin-bundle/views';
import {MetaGeneratorToolbarAction, AssistantFormBridge} from './containers';
import AssistantWindow from './containers/Assistant';
import ImageGeneratorOverlay from './containers/ImageGenerator';
import Launcher from './containers/Launcher';
import assistantContextStore from './stores/assistantContextStore';
import routerStore from './stores/routerStore';
import imageGeneratorStore from './stores/imageGeneratorStore';
import {PasswordField} from './fields';

// The admin router is created privately inside startAdmin(), which calls
// addUpdateAttributesHook right after construction - capture the instance
// there so the assistant can navigate from any view. Guarded so that a change
// to this Sulu internal degrades navigation gracefully instead of throwing at
// import time and taking down the whole admin bundle.
if (Router && typeof Router.prototype.addUpdateAttributesHook === 'function') {
    const originalAddUpdateAttributesHook = Router.prototype.addUpdateAttributesHook;
    Router.prototype.addUpdateAttributesHook = function(...args) {
        try {
            routerStore.setRouter(this);
        } catch (error) {
            // Router capture is best-effort; never break admin startup over it.
        }

        return originalAddUpdateAttributesHook.apply(this, args);
    };
}

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

        const launcherContainer = document.createElement('div');
        if (document.body) {
            document.body.appendChild(launcherContainer);
            render(<Launcher />, launcherContainer);
        }
    }
});
