import {initializer} from 'sulu-admin-bundle/services';
import {fieldRegistry} from 'sulu-admin-bundle/containers';
import {formToolbarActionRegistry} from 'sulu-admin-bundle/views';
import {MetaGeneratorToolbarAction, AssistantFormBridge} from './containers';
import {PasswordField} from './fields';

initializer.addUpdateConfigHook('sulu_admin', (config, initialized) => {
    if (!initialized) {
        formToolbarActionRegistry.add('sulu_ai.generate_meta', MetaGeneratorToolbarAction);
        formToolbarActionRegistry.add('sulu_ai.assistant', AssistantFormBridge);
        fieldRegistry.add('password', PasswordField);
    }
});
