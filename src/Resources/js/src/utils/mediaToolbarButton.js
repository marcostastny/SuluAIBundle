// @flow
import {translate} from 'sulu-admin-bundle/utils';
import imageGeneratorStore from '../stores/imageGeneratorStore';

const MARKER = 'data-sulu-ai-image-button';

function makeButton(context) {
    const button = document.createElement('button');
    button.setAttribute(MARKER, 'true');
    button.type = 'button';
    button.textContent = '✨ ' + translate('sulu_ai.image_generate');
    button.style.cssText = 'margin:0 8px;padding:6px 12px;border:0;border-radius:4px;'
        + 'background:#93c020;color:#1f242a;font-weight:600;cursor:pointer;';
    button.addEventListener('click', () => {
        imageGeneratorStore.openOverlay(context);
    });

    return button;
}

function currentLocale() {
    const match = window.location.hash.match(/\/collections\/([a-z]{2})(\/|$|\?)/);

    return match ? match[1] : 'en';
}

// Sulu wraps every media collection (overview and selection popup) in a
// MultiMediaDropzone, so its hashed "dropzone--" class is a reliable
// "media context" signal. The toolbars themselves live in different places:
//  - overview: the app-frame <nav class="toolbar--…"> (upload/delete/move)
//  - selection popup: the list's <div class="toolbar-right--…"> next to upload
// The MutationObserver re-runs on every DOM change, so placement self-heals
// when Sulu re-renders, and the button is removed when no media context shows.
function findMediaDropzone() {
    const dropzones = document.querySelectorAll('[class*="dropzone--"]');
    for (let i = 0; i < dropzones.length; i++) {
        // Ignore our own overlay, whose reference dropzone also hashes to "dropzone--".
        if (!dropzones[i].closest('[data-image-generator]')) {
            return dropzones[i];
        }
    }

    return null;
}

function inject() {
    if (!imageGeneratorStore.available) {
        return;
    }

    const dropzone = findMediaDropzone();
    const existing = document.querySelectorAll('[' + MARKER + ']');
    if (!dropzone) {
        existing.forEach((button) => button.remove());

        return;
    }

    const overlay = dropzone.closest('[class*="overlay--"]');
    const target = overlay
        ? overlay.querySelector('[class*="toolbar-right--"]')
        : document.querySelector('header nav[class*="toolbar--"]');
    if (!target) {
        return;
    }

    if (target.querySelector('[' + MARKER + ']')) {
        return;
    }
    existing.forEach((button) => {
        if (!target.contains(button)) {
            button.remove();
        }
    });

    const button = makeButton({locale: currentLocale(), collectionId: null});

    if (overlay) {
        // Selection popup: sit next to the "upload file" action.
        target.insertBefore(button, target.firstChild);

        return;
    }

    // Overview: place among the native action items (upload / delete / move).
    const grow = target.querySelector('[class*="controls--"][class*="grow--"]');
    const list = grow ? grow.querySelector('ul[class*="items--"]') : null;
    if (list) {
        const item = document.createElement('li');
        item.appendChild(button);
        list.appendChild(item);

        return;
    }
    (grow || target).appendChild(button);
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
