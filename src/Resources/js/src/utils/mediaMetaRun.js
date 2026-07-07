// @flow
// Pure state transitions for the media-meta batch loop. The store drives
// requests; every decision (what to send next, when to stop) lives here so
// it is testable without mobx/Requester.

export const BATCH_LIMIT = 5;
export const MAX_CONSECUTIVE_FAILURES = 3;
export const MAX_STAGNANT_BATCHES = 3;

export function createRun(mode: string, ids: Array<number>, total: number) {
    return {
        mode,
        queue: mode === 'selected' ? ids.slice() : [],
        total,
        processed: 0,
        skipped: 0,
        skippedIds: [],
        failed: 0,
        errors: [],
        consecutiveFailures: 0,
        // The missing-count from the confirm dialog doubles as the baseline
        // for stagnation detection.
        remaining: mode === 'missing' ? total : undefined,
        stagnantBatches: 0,
        cancelled: false,
        aborted: false,
        done: total === 0,
    };
}

export function nextRequestBody(run: Object) {
    if (run.mode === 'selected') {
        return {mode: 'selected', ids: run.queue.slice(0, BATCH_LIMIT)};
    }

    // Error'd AND skipped ids are excluded server-side: both stay "missing"
    // in the database, so without exclusion the finder would re-select the
    // same images every batch and the run would never terminate.
    return {
        mode: 'missing',
        limit: BATCH_LIMIT,
        excludeIds: [...run.errors.map((error) => error.id), ...run.skippedIds],
    };
}

export function applyBatchResponse(run: Object, response: Object) {
    const processed = (response.processed || []).length;
    const skipped = (response.skipped || []).length;
    const failed = (response.errors || []).length;
    const handled = processed + skipped + failed;

    const sent = run.mode === 'selected' ? Math.min(run.queue.length, BATCH_LIMIT) : 0;
    const queue = run.mode === 'selected' ? run.queue.slice(sent) : [];

    const remaining = response.remaining || 0;
    const done = run.mode === 'selected'
        ? queue.length === 0
        : remaining === 0;

    // Belt and braces against a server that keeps reporting work while none
    // gets absorbed (e.g. the excludeIds cap is exceeded): a batch that
    // handles nothing aborts immediately, a remaining count that refuses to
    // drop aborts after MAX_STAGNANT_BATCHES.
    const stagnantBatches = run.mode === 'missing'
        && typeof run.remaining === 'number' && remaining >= run.remaining
        ? run.stagnantBatches + 1
        : 0;
    const aborted = run.aborted
        || (!done && handled === 0)
        || (!done && stagnantBatches >= MAX_STAGNANT_BATCHES);

    return {
        ...run,
        queue,
        processed: run.processed + processed,
        skipped: run.skipped + skipped,
        skippedIds: [...run.skippedIds, ...(response.skipped || []).map((entry) => entry.id)],
        failed: run.failed + failed,
        errors: [...run.errors, ...(response.errors || [])],
        consecutiveFailures: 0,
        remaining,
        stagnantBatches,
        done,
        aborted,
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
