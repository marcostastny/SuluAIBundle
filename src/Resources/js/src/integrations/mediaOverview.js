// @flow
import {viewRegistry} from 'sulu-admin-bundle/containers';
import toolbarStorePool from 'sulu-admin-bundle/containers/Toolbar/stores/toolbarStorePool';
import {translate} from 'sulu-admin-bundle/utils';
import mediaMetaStore from '../stores/mediaMetaStore';

const MEDIA_OVERVIEW_VIEW = 'sulu_media.overview';

// Sulu's media overview toolbar is a closed closure (withToolbar) with no
// extension registry, so the AI buttons are injected via two guarded
// interceptions - same style as the Router capture in app.js:
// 1. viewRegistry.add is wrapped to subclass the media overview view so the
//    live instance (selection, reload) is published to mediaMetaStore.
// 2. toolbarStorePool.setToolbarConfig is wrapped to append the two buttons
//    whenever that instance is mounted.
// Every step degrades gracefully: if a Sulu internal changes, the media
// section works as before and the AI buttons simply do not appear.
export default function installMediaOverviewIntegration() {
    try {
        installViewCapture();
        installToolbarAppend();
    } catch (error) {
        // Never break admin startup over an optional integration.
    }
}

function installViewCapture() {
    if (!viewRegistry || typeof viewRegistry.add !== 'function') {
        return;
    }

    const originalAdd = viewRegistry.add;
    viewRegistry.add = function(name, view, ...rest) {
        if (name === MEDIA_OVERVIEW_VIEW && typeof view === 'function') {
            try {
                view = withInstanceCapture(view);
            } catch (error) {
                // Fall through with the original view.
            }
        }

        return originalAdd.call(this, name, view, ...rest);
    };
}

function withInstanceCapture(View) {
    class AiMediaOverview extends View {
        componentDidMount() {
            if (super.componentDidMount) {
                super.componentDidMount();
            }
            mediaMetaStore.setOverview(this);
        }

        componentWillUnmount() {
            mediaMetaStore.clearOverview(this);
            if (super.componentWillUnmount) {
                super.componentWillUnmount();
            }
        }
    }
    AiMediaOverview.displayName = 'AiMediaOverview';

    return AiMediaOverview;
}

function installToolbarAppend() {
    if (!toolbarStorePool || typeof toolbarStorePool.setToolbarConfig !== 'function') {
        return;
    }

    const originalSet = toolbarStorePool.setToolbarConfig;
    toolbarStorePool.setToolbarConfig = (key, config) => {
        try {
            config = appendAiButtons(config);
        } catch (error) {
            // Keep the native toolbar untouched on any failure.
        }

        return originalSet(key, config);
    };
}

function appendAiButtons(config) {
    const overview = mediaMetaStore.overview;
    if (!mediaMetaStore.available || !overview || !config || !Array.isArray(config.items)) {
        return config;
    }

    // Reading selectionIds here makes it a dependency of the caller's mobx
    // autorun, so selection changes rebuild the toolbar with fresh state.
    const selectionCount = overview.mediaListStore ? overview.mediaListStore.selectionIds.length : 0;

    return {
        ...config,
        items: [
            ...config.items,
            {
                icon: 'su-magic',
                label: translate('sulu_ai.media_meta_generate_missing'),
                onClick: () => mediaMetaStore.openMissing(),
                type: 'button',
            },
            {
                disabled: selectionCount === 0,
                icon: 'su-magic',
                label: translate('sulu_ai.media_meta_generate_selected'),
                onClick: () => mediaMetaStore.openSelected(),
                type: 'button',
            },
        ],
    };
}
