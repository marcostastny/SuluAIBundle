// @flow
import {action, observable} from 'mobx';

class ImageGeneratorStore {
    @observable available = false;
    @observable models = [];
    @observable open = false;
    @observable.ref context = null;

    @action setConfig(config) {
        this.available = Boolean(config && config.available);
        this.models = (config && config.models) || [];
    }

    @action openOverlay(context) {
        this.context = context || {collectionId: null, locale: 'en'};
        this.open = true;
    }

    @action close() {
        this.open = false;
    }
}

export default new ImageGeneratorStore();
