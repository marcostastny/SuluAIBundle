// @flow
import {action, computed, observable} from 'mobx';
import {Requester} from 'sulu-admin-bundle/services';
import {
    applyBatchResponse,
    applyRequestFailure,
    createRun,
    isFinished,
    nextRequestBody,
} from '../utils/mediaMetaRun';

const BATCH_ENDPOINT = '/admin/api/ai/media-meta/batch';
const COUNT_ENDPOINT = '/admin/api/ai/media-meta/missing-count';

class MediaMetaStore {
    @observable available = false;
    // The live MediaOverview view instance (published by the integration
    // subclass) - source of the list selection and the reload handle.
    @observable.ref overview = null;
    @observable open = false;
    @observable phase = 'confirm';
    @observable mode = 'missing';
    @observable count = 0;
    @observable countLoading = false;
    @observable.ref run = null;
    selectedIds = [];
    // Monotonic token so responses of a superseded/closed run are ignored.
    runToken = 0;

    @action setAvailable(available: boolean) {
        this.available = Boolean(available);
    }

    @action setOverview(view: Object) {
        this.overview = view;
    }

    @action clearOverview(view: Object) {
        if (this.overview === view) {
            this.overview = null;
        }
    }

    @computed get selectionCount() {
        const overview = this.overview;
        if (!overview || !overview.mediaListStore) {
            return 0;
        }

        return overview.mediaListStore.selectionIds.length;
    }

    @action openMissing() {
        this.mode = 'missing';
        this.phase = 'confirm';
        this.count = 0;
        this.countLoading = true;
        this.run = null;
        this.open = true;
        Requester.get(COUNT_ENDPOINT).then(action((response) => {
            this.count = (response && response.count) || 0;
            this.countLoading = false;
        })).catch(action(() => {
            this.countLoading = false;
        }));
    }

    @action openSelected() {
        const overview = this.overview;
        this.selectedIds = overview && overview.mediaListStore
            ? overview.mediaListStore.selectionIds.slice()
            : [];
        this.mode = 'selected';
        this.phase = 'confirm';
        this.count = this.selectedIds.length;
        this.countLoading = false;
        this.run = null;
        this.open = true;
    }

    @action start() {
        const token = ++this.runToken;
        this.run = createRun(this.mode, this.selectedIds, this.count);
        this.phase = 'running';
        if (isFinished(this.run)) {
            this.finish();

            return;
        }
        this.request(token);
    }

    request(token: number) {
        Requester.post(BATCH_ENDPOINT, nextRequestBody(this.run)).then(action((response) => {
            if (token !== this.runToken) {
                return;
            }
            this.run = applyBatchResponse(this.run, response);
            this.advance(token);
        })).catch(action(() => {
            if (token !== this.runToken) {
                return;
            }
            this.run = applyRequestFailure(this.run);
            this.advance(token);
        }));
    }

    @action advance(token: number) {
        if (isFinished(this.run)) {
            this.finish();

            return;
        }
        this.request(token);
    }

    // The in-flight request still resolves and applies its result before
    // advance() sees the flag: cancel stops AFTER the current batch.
    @action cancel() {
        if (this.run) {
            this.run = {...this.run, cancelled: true};
        }
    }

    @action finish() {
        this.phase = 'summary';
        const overview = this.overview;
        if (this.run && this.run.processed > 0 && overview && overview.mediaListStore) {
            overview.mediaListStore.reload();
        }
    }

    @action close() {
        this.runToken++;
        this.open = false;
        this.run = null;
        this.phase = 'confirm';
    }
}

export default new MediaMetaStore();
