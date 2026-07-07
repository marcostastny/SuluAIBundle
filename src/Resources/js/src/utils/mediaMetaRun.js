// @flow
// Pure state transitions for the media-meta batch loop. The store drives
// requests; every decision (what to send next, when to stop) lives here so
// it is testable without mobx/Requester.

export const BATCH_LIMIT = 5;
export const MAX_CONSECUTIVE_FAILURES = 3;

export function createRun(mode: string, ids: Array<number>, total: number) {
    return {
        mode,
        queue: mode === 'selected' ? ids.slice() : [],
        total,
        processed: 0,
        skipped: 0,
        failed: 0,
        errors: [],
        consecutiveFailures: 0,
        cancelled: false,
        aborted: false,
        done: total === 0,
    };
}

export function nextRequestBody(run: Object) {
    if (run.mode === 'selected') {
        return {mode: 'selected', ids: run.queue.slice(0, BATCH_LIMIT)};
    }

    // Error'd ids are excluded server-side so a persistently failing image
    // cannot be re-selected every batch (the run would never terminate).
    return {mode: 'missing', limit: BATCH_LIMIT, excludeIds: run.errors.map((error) => error.id)};
}

export function applyBatchResponse(run: Object, response: Object) {
    const processed = (response.processed || []).length;
    const skipped = (response.skipped || []).length;
    const failed = (response.errors || []).length;
    const handled = processed + skipped + failed;

    const sent = run.mode === 'selected' ? Math.min(run.queue.length, BATCH_LIMIT) : 0;
    const queue = run.mode === 'selected' ? run.queue.slice(sent) : [];

    const done = run.mode === 'selected'
        ? queue.length === 0
        : (response.remaining || 0) === 0;

    return {
        ...run,
        queue,
        processed: run.processed + processed,
        skipped: run.skipped + skipped,
        failed: run.failed + failed,
        errors: [...run.errors, ...(response.errors || [])],
        consecutiveFailures: 0,
        done,
        // Belt and braces: a batch that handled nothing while claiming there
        // is more to do would loop forever - abort instead.
        aborted: run.aborted || (!done && handled === 0),
    };
}

export function applyRequestFailure(run: Object) {
    const consecutiveFailures = run.consecutiveFailures + 1;

    return {
        ...run,
        consecutiveFailures,
        aborted: run.aborted || consecutiveFailures >= MAX_CONSECUTIVE_FAILURES,
    };
}

export function isFinished(run: Object) {
    return Boolean(run.done || run.aborted || run.cancelled);
}

export function progressValue(run: Object) {
    return run.processed + run.skipped + run.failed;
}
