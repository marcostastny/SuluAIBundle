// @flow
import {
    BATCH_LIMIT,
    applyBatchResponse,
    applyRequestFailure,
    createRun,
    isFinished,
    nextRequestBody,
    progressValue,
} from '../src/utils/mediaMetaRun';

test('createRun for zero total is immediately done', () => {
    expect(createRun('missing', [], 0).done).toBe(true);
    expect(isFinished(createRun('missing', [], 0))).toBe(true);
});

test('missing mode requests carry limit and accumulated error AND skipped ids', () => {
    let run = createRun('missing', [], 10);
    expect(nextRequestBody(run)).toEqual({mode: 'missing', limit: BATCH_LIMIT, excludeIds: []});

    // Skipped images (e.g. file missing on disk) stay "missing" in the DB -
    // they must be excluded like errors or the finder re-selects them forever.
    run = applyBatchResponse(run, {
        processed: [{id: 1, locales: {}}],
        skipped: [{id: 3, reason: 'no-preview'}],
        errors: [{id: 2, message: 'boom'}],
        remaining: 7,
    });
    expect(nextRequestBody(run)).toEqual({mode: 'missing', limit: BATCH_LIMIT, excludeIds: [2, 3]});
});

test('a stagnating remaining count aborts after three batches', () => {
    // Pathological case: server-side excludeIds cap exceeded, so remaining
    // never drops although items are "handled" (skipped) every time.
    let run = createRun('missing', [], 600);
    run = applyBatchResponse(run, {processed: [], skipped: [{id: 1}, {id: 2}], errors: [], remaining: 600});
    expect(run.aborted).toBe(false);
    run = applyBatchResponse(run, {processed: [], skipped: [{id: 3}, {id: 4}], errors: [], remaining: 600});
    expect(run.aborted).toBe(false);
    run = applyBatchResponse(run, {processed: [], skipped: [{id: 5}, {id: 6}], errors: [], remaining: 600});
    expect(run.aborted).toBe(true);

    // A decreasing remaining resets the stagnation counter.
    let healthy = createRun('missing', [], 600);
    healthy = applyBatchResponse(healthy, {processed: [], skipped: [{id: 1}], errors: [], remaining: 600});
    healthy = applyBatchResponse(healthy, {processed: [{id: 2}], skipped: [], errors: [], remaining: 599});
    healthy = applyBatchResponse(healthy, {processed: [], skipped: [{id: 3}], errors: [], remaining: 599});
    healthy = applyBatchResponse(healthy, {processed: [], skipped: [{id: 4}], errors: [], remaining: 599});
    expect(healthy.aborted).toBe(false);
});

test('selected mode chunks the id queue', () => {
    let run = createRun('selected', [1, 2, 3, 4, 5, 6, 7], 7);
    expect(nextRequestBody(run)).toEqual({mode: 'selected', ids: [1, 2, 3, 4, 5]});

    run = applyBatchResponse(run, {
        processed: [{id: 1}, {id: 3}, {id: 4}, {id: 5}],
        skipped: [{id: 2, reason: 'not-an-image'}],
        errors: [],
        remaining: 0,
    });
    expect(run.done).toBe(false);
    expect(nextRequestBody(run)).toEqual({mode: 'selected', ids: [6, 7]});
});

test('counts accumulate and missing mode finishes at remaining 0', () => {
    let run = createRun('missing', [], 3);
    run = applyBatchResponse(run, {processed: [{id: 1}, {id: 2}], skipped: [], errors: [], remaining: 1});
    expect(run.done).toBe(false);
    run = applyBatchResponse(run, {processed: [{id: 3}], skipped: [], errors: [], remaining: 0});

    expect(run.processed).toBe(3);
    expect(run.done).toBe(true);
    expect(progressValue(run)).toBe(3);
});

test('a batch with zero progress aborts the run', () => {
    let run = createRun('missing', [], 5);
    run = applyBatchResponse(run, {processed: [], skipped: [], errors: [], remaining: 5});

    expect(run.aborted).toBe(true);
    expect(isFinished(run)).toBe(true);
});

test('three consecutive request failures abort, a success in between resets', () => {
    let run = createRun('missing', [], 5);
    run = applyRequestFailure(run);
    run = applyRequestFailure(run);
    expect(run.aborted).toBe(false);
    run = applyBatchResponse(run, {processed: [{id: 1}], skipped: [], errors: [], remaining: 4});
    expect(run.consecutiveFailures).toBe(0);
    run = applyRequestFailure(run);
    run = applyRequestFailure(run);
    run = applyRequestFailure(run);
    expect(run.aborted).toBe(true);
});

test('cancelled runs are finished', () => {
    const run = {...createRun('missing', [], 5), cancelled: true};
    expect(isFinished(run)).toBe(true);
});
