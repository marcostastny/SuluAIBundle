// @flow
import {translate} from 'sulu-admin-bundle/utils';
import imageGeneratorStore from '../stores/imageGeneratorStore';

const MARKER = 'data-sulu-ai-image-button';

function makeButton(context) {
    const button = document.createElement('button');
    button.setAttribute(MARKER, 'true');
    button.type = 'button';
    button.textContent = '✨ ' + translate('sulu_ai.image_generate');
    button.style.cssText = 'margin:6px 8px;padding:6px 12px;border:0;border-radius:4px;'
        + 'background:#93c020;color:#1f242a;font-weight:600;cursor:pointer;';
    button.addEventListener('click', () => {
        imageGeneratorStore.openOverlay(context);
    });

    return button;
}

function currentLocale() {
    const match = window.location.hash.match(/\/collections\/([a-z]{2})(\/|$)/);

    return match ? match[1] : 'en';
}

function inject() {
    if (!imageGeneratorStore.available) {
        return;
    }

    // Media overview and the media-selection overlay both render a Sulu toolbar.
    const toolbars = document.querySelectorAll('.su-toolbar-controls, [class*="toolbar"] [class*="controls"]');
    toolbars.forEach((toolbar) => {
        if (toolbar.querySelector('[' + MARKER + ']')) {
            return;
        }
        // Only augment toolbars that live inside a media context (overview or selection overlay).
        const inMediaContext = toolbar.closest('[class*="mediaCollection"], [class*="mediaOverview"], '
            + '[class*="mediaSelectionOverlay"], [class*="MediaSelectionOverlay"]');
        if (!inMediaContext) {
            return;
        }
        toolbar.appendChild(makeButton({locale: currentLocale(), collectionId: null}));
    });
}

export default function startMediaToolbarButtonObserver() {
    const observer = new MutationObserver(() => {
        inject();
    });
    if (document.body) {
        observer.observe(document.body, {childList: true, subtree: true});
    }
    inject();
}
