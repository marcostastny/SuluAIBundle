// @flow
import {toJS} from 'mobx';

export class ApplyConflictError extends Error {}

const cloneDeep = (value) => {
    if (Array.isArray(value)) {
        return value.map(cloneDeep);
    }
    if (value && typeof value === 'object') {
        const clone = {};
        for (const key of Object.keys(value)) {
            clone[key] = cloneDeep(value[key]);
        }

        return clone;
    }

    return value;
};

// Block-op paths may descend through nested block lists
// ("/blocks/3/cards/0/rows"): segments alternate block property and index.
// The "container path" of an op is the block list it works on, written
// exactly as in the op — baselines are keyed by these strings, so for
// one-level ops the keys equal the plain property names as before.

const segmentsOf = (path) => path.replace(/^\//, '').split('/');

// Every block list an op's path descends through, e.g. setBlockField
// "/blocks/3/cards/0/rows/5/value" → ['blocks', 'blocks/3/cards',
// 'blocks/3/cards/0/rows'].
const containerChainOf = (op) => {
    let segments;
    switch (op.op) {
        case 'setBlockField':
            segments = segmentsOf(op.path).slice(0, -2);
            break;
        case 'insertBlock':
        case 'removeBlock':
        case 'moveBlock':
            segments = segmentsOf(op.path);
            break;
        default:
            return [];
    }
    const chain = [];
    for (let end = 1; end <= segments.length; end += 2) {
        chain.push(segments.slice(0, end).join('/'));
    }

    return chain;
};

const blocksAt = (data, containerPath) => {
    const segments = containerPath.split('/');
    let blocks = data[segments[0]];
    for (let i = 1; i < segments.length; i += 2) {
        const block = Array.isArray(blocks) ? blocks[parseInt(segments[i])] : undefined;
        blocks = block && typeof block === 'object' ? block[segments[i + 1]] : undefined;
    }

    return Array.isArray(blocks) ? blocks : null;
};

/**
 * Records the block types of every block list the ops descend through or work
 * on, keyed by container path, taken from the form data as it was when the
 * proposal was made. Passed back to applyOps so it can detect when a targeted
 * block has since been reordered or replaced by a different block.
 */
export function snapshotBlockTypes(data, ops) {
    const baseline = {};
    for (const op of ops) {
        for (const containerPath of containerChainOf(op)) {
            if (baseline[containerPath]) {
                continue;
            }
            const blocks = blocksAt(data, containerPath);
            baseline[containerPath] = (blocks || []).map((block) => (block && block.type) || '');
        }
    }

    return baseline;
}

/**
 * Applies approved assistant ops to the open form via resourceFormStore.change().
 * Touched top-level block arrays are deep-cloned once, all mutations (at any
 * nesting depth) happen on the clone, and each top-level property is written
 * back once. Throws ApplyConflictError when a path no longer matches the
 * current form data (the user edited the form after the proposal was made), or
 * when a block's type differs from the baseline snapshot.
 */
export default function applyOps(resourceFormStore, ops, baseline = {}) {
    const data = toJS(resourceFormStore.data);
    const topChanges = {};
    // Working copies of the proposal-time block types, spliced by the same
    // structural ops as the block arrays so later indices still line up with
    // the blocks they targeted (a static snapshot would not).
    const baselineWork = {};

    const topArray = (property) => {
        if (!topChanges[property]) {
            topChanges[property] = Array.isArray(data[property]) ? cloneDeep(data[property]) : [];
        }

        return topChanges[property];
    };

    const getBaseline = (containerPath) => {
        if (!(containerPath in baselineWork)) {
            baselineWork[containerPath] = Array.isArray(baseline[containerPath]) ? [...baseline[containerPath]] : null;
        }

        return baselineWork[containerPath];
    };

    const checkedBlock = (containerPath, blocks, index) => {
        const block = blocks[index];
        if (!block) {
            throw new ApplyConflictError('Block ' + index + ' of "' + containerPath + '" no longer exists.');
        }
        const types = getBaseline(containerPath);
        const expectedType = types ? types[index] : undefined;
        if (expectedType !== undefined && (block.type || '') !== expectedType) {
            throw new ApplyConflictError(
                'Block ' + index + ' of "' + containerPath + '" changed since the proposal was made.'
            );
        }

        return block;
    };

    // Walks container segments (property/index alternating, ending in a
    // property) on the working copy, verifying every block on the way.
    const containerAt = (segments) => {
        let containerPath = segments[0];
        let blocks = topArray(segments[0]);
        for (let i = 1; i < segments.length; i += 2) {
            const block = checkedBlock(containerPath, blocks, parseInt(segments[i]));
            if (!Array.isArray(block[segments[i + 1]])) {
                block[segments[i + 1]] = [];
            }
            blocks = block[segments[i + 1]];
            containerPath += '/' + segments[i] + '/' + segments[i + 1];
        }

        return {blocks, containerPath};
    };

    const scalarChanges = [];

    for (const op of ops) {
        switch (op.op) {
            case 'set': {
                scalarChanges.push([op.path, op.value]);
                break;
            }
            case 'setBlockField': {
                const segments = segmentsOf(op.path);
                const field = segments.pop();
                const index = parseInt(segments.pop());
                const {blocks, containerPath} = containerAt(segments);
                const block = checkedBlock(containerPath, blocks, index);
                block[field] = op.value;
                break;
            }
            case 'insertBlock': {
                const {blocks, containerPath} = containerAt(segmentsOf(op.path));
                if (op.index < 0 || op.index > blocks.length) {
                    throw new ApplyConflictError('Insert position ' + op.index + ' is out of range.');
                }
                blocks.splice(op.index, 0, op.block);
                const types = getBaseline(containerPath);
                if (types) {
                    types.splice(op.index, 0, (op.block && op.block.type) || '');
                }
                break;
            }
            case 'removeBlock': {
                const {blocks, containerPath} = containerAt(segmentsOf(op.path));
                if (op.index < 0 || op.index >= blocks.length) {
                    throw new ApplyConflictError('Block ' + op.index + ' of "' + containerPath + '" no longer exists.');
                }
                blocks.splice(op.index, 1);
                const types = getBaseline(containerPath);
                if (types) {
                    types.splice(op.index, 1);
                }
                break;
            }
            case 'moveBlock': {
                const {blocks, containerPath} = containerAt(segmentsOf(op.path));
                if (op.from < 0 || op.from >= blocks.length || op.to < 0 || op.to >= blocks.length) {
                    throw new ApplyConflictError('Move ' + op.from + ' -> ' + op.to + ' is out of range.');
                }
                const [moved] = blocks.splice(op.from, 1);
                blocks.splice(op.to, 0, moved);
                const types = getBaseline(containerPath);
                if (types) {
                    const [movedType] = types.splice(op.from, 1);
                    types.splice(op.to, 0, movedType);
                }
                break;
            }
            default:
                throw new ApplyConflictError('Unknown operation "' + String(op.op) + '".');
        }
    }

    // All ops validated — write everything in one pass.
    for (const [path, value] of scalarChanges) {
        resourceFormStore.change(path, value);
    }
    for (const property of Object.keys(topChanges)) {
        resourceFormStore.change('/' + property, topChanges[property]);
    }
}
