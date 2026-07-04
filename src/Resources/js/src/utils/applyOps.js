// @flow
import {toJS} from 'mobx';

export class ApplyConflictError extends Error {}

const blockPropertyOf = (op) => {
    switch (op.op) {
        case 'setBlockField':
            return op.path.split('/')[1];
        case 'insertBlock':
        case 'removeBlock':
        case 'moveBlock':
            return op.path.slice(1);
        default:
            return null;
    }
};

/**
 * Records the block type present at each index of every block property the ops
 * touch, taken from the form data as it was when the proposal was made. Passed
 * back to applyOps so it can detect when the block a setBlockField op targeted
 * has since been reordered or replaced by a different block.
 */
export function snapshotBlockTypes(data, ops) {
    const baseline = {};
    for (const op of ops) {
        const property = blockPropertyOf(op);
        if (property === null || baseline[property]) {
            continue;
        }
        const blocks = Array.isArray(data[property]) ? data[property] : [];
        baseline[property] = blocks.map((block) => (block && block.type) || '');
    }

    return baseline;
}

/**
 * Applies approved assistant ops to the open form via resourceFormStore.change().
 * Block arrays are transformed on a working copy and written back once per
 * block property. Throws ApplyConflictError when indices no longer match the
 * current form data (the user edited the form after the proposal was made), or
 * when a targeted block's type no longer matches the baseline snapshot.
 */
export default function applyOps(resourceFormStore, ops, baseline = {}) {
    const data = toJS(resourceFormStore.data);
    const blockChanges = {};

    const getBlocks = (property) => {
        if (!blockChanges[property]) {
            blockChanges[property] = Array.isArray(data[property]) ? [...data[property]] : [];
        }

        return blockChanges[property];
    };

    const scalarChanges = [];

    for (const op of ops) {
        switch (op.op) {
            case 'set': {
                scalarChanges.push([op.path, op.value]);
                break;
            }
            case 'setBlockField': {
                const segments = op.path.split('/');
                const property = segments[1];
                const index = parseInt(segments[2]);
                const field = segments[3];
                const blocks = getBlocks(property);
                if (!blocks[index]) {
                    throw new ApplyConflictError('Block ' + index + ' of "' + property + '" no longer exists.');
                }
                const expectedType = baseline[property] ? baseline[property][index] : undefined;
                if (expectedType !== undefined && (blocks[index].type || '') !== expectedType) {
                    throw new ApplyConflictError(
                        'Block ' + index + ' of "' + property + '" changed since the proposal was made.'
                    );
                }
                blocks[index] = {...blocks[index], [field]: op.value};
                break;
            }
            case 'insertBlock': {
                const property = op.path.slice(1);
                const blocks = getBlocks(property);
                if (op.index < 0 || op.index > blocks.length) {
                    throw new ApplyConflictError('Insert position ' + op.index + ' is out of range.');
                }
                blocks.splice(op.index, 0, op.block);
                break;
            }
            case 'removeBlock': {
                const property = op.path.slice(1);
                const blocks = getBlocks(property);
                if (op.index < 0 || op.index >= blocks.length) {
                    throw new ApplyConflictError('Block ' + op.index + ' of "' + property + '" no longer exists.');
                }
                blocks.splice(op.index, 1);
                break;
            }
            case 'moveBlock': {
                const property = op.path.slice(1);
                const blocks = getBlocks(property);
                if (op.from < 0 || op.from >= blocks.length || op.to < 0 || op.to >= blocks.length) {
                    throw new ApplyConflictError('Move ' + op.from + ' -> ' + op.to + ' is out of range.');
                }
                const [moved] = blocks.splice(op.from, 1);
                blocks.splice(op.to, 0, moved);
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
    for (const property of Object.keys(blockChanges)) {
        resourceFormStore.change('/' + property, blockChanges[property]);
    }
}
