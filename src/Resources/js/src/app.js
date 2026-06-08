import {initializer} from 'sulu-admin-bundle/services';
import {formToolbarActionRegistry} from 'sulu-admin-bundle/views';
import {MetaGeneratorToolbarAction} from './containers';

initializer.addUpdateConfigHook('sulu_admin', (config, initialized) => {
    if (!initialized) {
        formToolbarActionRegistry.add('sulu_ai.generate_meta', MetaGeneratorToolbarAction);
    }
});
