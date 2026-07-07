<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

/**
 * Validates assistant-proposed edit operations against the template schema and
 * the submitted form data. Block paths may descend into nested block lists
 * ("/blocks/3/cards/0/rows"). Indices refer to the state after previous ops in
 * the same list have been applied, so structural ops are replayed on a working
 * copy of the touched block arrays.
 */
class EditOpValidator
{
    /**
     * Field types the assistant may write via set/setBlockField/insertBlock.
     * These take a scalar (or a flat list of scalars). Everything else —
     * media/page/snippet/teaser selections, smart_content, etc. — expects a
     * structured value, so a scalar would corrupt the field; those are
     * rejected rather than validated. Nested block lists are the exception:
     * they are addressed through extended paths (or, inside insertBlock
     * values, as lists of block objects).
     *
     * @var string[]
     */
    private const EDITABLE_FIELD_TYPES = [
        'text_line', 'text_area', 'text_editor', 'email', 'url', 'phone',
        'password', 'number', 'single_select', 'checkbox', 'color',
        'date', 'time', 'datetime',
    ];

    /**
     * @param array<int, mixed> $ops
     * @param array{fields?: array<string, array<string, mixed>>} $schema
     * @param array<string, mixed> $formData
     *
     * @return list<string>
     */
    public function validate(array $ops, array $schema, array $formData): array
    {
        $fields = $schema['fields'] ?? [];
        // Working copies of the top-level block arrays the ops touch, mutated
        // by structural ops so later indices validate against post-op state.
        $state = [];
        // Container paths already restructured in this list; descending
        // through them is rejected (the client baseline keys proposal-time
        // paths, which an earlier splice would invalidate).
        $modified = [];
        $errors = [];

        foreach ($ops as $i => $op) {
            $error = $this->validateOp(\is_array($op) ? $op : [], $fields, $formData, $state, $modified);
            if (null !== $error) {
                $errors[] = \sprintf('op %d: %s', $i, $error);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $op
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $formData
     * @param array<string, array<int, mixed>> $state
     * @param list<string> $modified
     */
    private function validateOp(array $op, array $fields, array $formData, array &$state, array &$modified): ?string
    {
        $kind = (string) ($op['op'] ?? '');
        $path = (string) ($op['path'] ?? '');

        switch ($kind) {
            case 'set':
                if (!\str_starts_with($path, '/') || '/' === $path) {
                    return \sprintf('"%s" is not a valid set path, expected "/<property>".', $path);
                }
                // Property names may themselves contain slashes (e.g. "seo/title"
                // on the SEO tab), so the whole remainder is the property name.
                $property = \substr($path, 1);
                if (!isset($fields[$property])) {
                    return \sprintf('unknown property "%s".', $property);
                }
                if ('block' === $fields[$property]['type']) {
                    return \sprintf('"%s" is a block property; use setBlockField/insertBlock/removeBlock/moveBlock.', $property);
                }
                if (!$this->isEditableField($fields[$property])) {
                    return \sprintf('"%s" is a "%s" field, which the assistant cannot edit.', $property, (string) ($fields[$property]['type'] ?? ''));
                }
                if (!$this->isValidFieldValue($op['value'] ?? null)) {
                    return \sprintf('value for "%s" must be a scalar or a list of scalars.', $property);
                }

                return null;

            case 'setBlockField':
                $segments = $this->segments($path);
                if (null === $segments || \count($segments) < 3 || 0 === \count($segments) % 2) {
                    return \sprintf('"%s" is not a valid setBlockField path, expected "/<blockProperty>/<index>/<field>".', $path);
                }
                $blockField = \array_pop($segments);
                $index = \array_pop($segments);
                if (!\ctype_digit($index)) {
                    return \sprintf('"%s" is not a valid setBlockField path, expected "/<blockProperty>/<index>/<field>".', $path);
                }
                $index = (int) $index;
                $resolved = $this->resolveContainer($segments, $fields, $formData, $state, $modified);
                if (\is_string($resolved)) {
                    return $resolved;
                }
                if ($index >= \count($resolved['blocks'])) {
                    return \sprintf('block index %d is out of range for "%s" (%d blocks).', $index, $resolved['path'], \count($resolved['blocks']));
                }
                $block = \is_array($resolved['blocks'][$index]) ? $resolved['blocks'][$index] : [];
                $blockType = (string) ($block['type'] ?? '');
                $typeFields = $resolved['blockTypes'][$blockType]['fields'] ?? [];
                if (!isset($typeFields[$blockField])) {
                    return \sprintf('block %d of "%s" is a "%s" block, which has no field "%s".', $index, $resolved['path'], $blockType, $blockField);
                }
                if (!$this->isEditableField($typeFields[$blockField])) {
                    return \sprintf('field "%s" of block "%s" is a "%s" field, which the assistant cannot edit.', $blockField, $blockType, (string) ($typeFields[$blockField]['type'] ?? ''));
                }
                if (!$this->isValidFieldValue($op['value'] ?? null)) {
                    return \sprintf('value for "%s/%d/%s" must be a scalar or a list of scalars.', $resolved['path'], $index, $blockField);
                }

                return null;

            case 'insertBlock':
            case 'removeBlock':
            case 'moveBlock':
                $segments = $this->segments($path);
                if (null === $segments || 0 === \count($segments) % 2) {
                    return \sprintf('"%s" is not a valid block path, expected "/<blockProperty>".', $path);
                }
                $resolved = $this->resolveContainer($segments, $fields, $formData, $state, $modified);
                if (\is_string($resolved)) {
                    return $resolved;
                }

                return $this->validateStructuralOp($kind, $op, $resolved, $state, $modified);

            default:
                return \sprintf('unknown op "%s".', $kind);
        }
    }

    /**
     * @param array<string, mixed> $op
     * @param array{path: string, blocks: list<mixed>, blockTypes: array<string, array<string, mixed>>, top: string, descent: list<array{int, string}>} $resolved
     * @param array<string, array<int, mixed>> $state
     * @param list<string> $modified
     */
    private function validateStructuralOp(string $kind, array $op, array $resolved, array &$state, array &$modified): ?string
    {
        $count = \count($resolved['blocks']);

        if ('insertBlock' === $kind) {
            $index = (int) ($op['index'] ?? -1);
            if ($index < 0 || $index > $count) {
                return \sprintf('insert index %d is out of range for "%s" (0..%d).', $index, $resolved['path'], $count);
            }
            $block = \is_array($op['block'] ?? null) ? $op['block'] : [];
            if (null !== $error = $this->validateBlockValue($block, $resolved['blockTypes'])) {
                return $error;
            }
            $this->mutateContainer($state[$resolved['top']], $resolved['descent'], static function (array &$blocks) use ($index, $block): void {
                \array_splice($blocks, $index, 0, [$block]);
            });
            $modified[] = $resolved['path'];

            return null;
        }

        if ('removeBlock' === $kind) {
            $index = (int) ($op['index'] ?? -1);
            if ($index < 0 || $index >= $count) {
                return \sprintf('remove index %d is out of range for "%s" (%d blocks).', $index, $resolved['path'], $count);
            }
            $this->mutateContainer($state[$resolved['top']], $resolved['descent'], static function (array &$blocks) use ($index): void {
                \array_splice($blocks, $index, 1);
            });
            $modified[] = $resolved['path'];

            return null;
        }

        $from = (int) ($op['from'] ?? -1);
        $to = (int) ($op['to'] ?? -1);
        if ($from < 0 || $from >= $count || $to < 0 || $to >= $count) {
            return \sprintf('move %d -> %d is out of range for "%s" (%d blocks).', $from, $to, $resolved['path'], $count);
        }
        $this->mutateContainer($state[$resolved['top']], $resolved['descent'], static function (array &$blocks) use ($from, $to): void {
            $moved = \array_splice($blocks, $from, 1);
            \array_splice($blocks, $to, 0, $moved);
        });
        $modified[] = $resolved['path'];

        return null;
    }

    /**
     * Validates a full block value as inserted. Fields of type "block" accept
     * a list of nested block objects, validated recursively against that
     * field's blockTypes.
     *
     * @param array<string, mixed> $block
     * @param array<string, array<string, mixed>> $blockTypes
     */
    private function validateBlockValue(array $block, array $blockTypes): ?string
    {
        $blockType = (string) ($block['type'] ?? '');
        if (!isset($blockTypes[$blockType])) {
            return \sprintf('unknown block type "%s"; available: %s.', $blockType, \implode(', ', \array_keys($blockTypes)));
        }
        $typeFields = $blockTypes[$blockType]['fields'] ?? [];
        foreach ($block as $key => $blockValue) {
            if ('type' === $key || 'settings' === $key) {
                continue;
            }
            $blockFieldDef = $typeFields[$key] ?? null;
            if (null === $blockFieldDef) {
                return \sprintf('block type "%s" has no field "%s".', $blockType, $key);
            }
            if ('block' === ($blockFieldDef['type'] ?? '')) {
                if (!\is_array($blockValue) || !\array_is_list($blockValue)) {
                    return \sprintf('field "%s" of block "%s" must be a list of nested blocks.', $key, $blockType);
                }
                foreach ($blockValue as $child) {
                    $error = $this->validateBlockValue(\is_array($child) ? $child : [], $blockFieldDef['blockTypes'] ?? []);
                    if (null !== $error) {
                        return $error;
                    }
                }

                continue;
            }
            if (!$this->isEditableField($blockFieldDef)) {
                return \sprintf('field "%s" of block "%s" is a "%s" field, which the assistant cannot set.', $key, $blockType, (string) ($blockFieldDef['type'] ?? ''));
            }
            if (!$this->isValidFieldValue($blockValue)) {
                return \sprintf('value for field "%s" of block "%s" must be a scalar or a list of scalars.', $key, $blockType);
            }
        }

        return null;
    }

    /**
     * @return list<string>|null null when the path is not "/a/b/…" shaped
     */
    private function segments(string $path): ?array
    {
        if (!\str_starts_with($path, '/') || '/' === $path) {
            return null;
        }
        $segments = \explode('/', \substr($path, 1));
        foreach ($segments as $segment) {
            if ('' === $segment) {
                return null;
            }
        }

        return $segments;
    }

    /**
     * Walks "<blockProperty>(/<index>/<blockProperty>)*" segments to a
     * (possibly nested) block list on the working state, verifying every step
     * against the schema. Returns an error string on mismatch.
     *
     * @param list<string> $segments
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $formData
     * @param array<string, array<int, mixed>> $state
     * @param list<string> $modified
     *
     * @return array{path: string, blocks: list<mixed>, blockTypes: array<string, array<string, mixed>>, top: string, descent: list<array{int, string}>}|string
     */
    private function resolveContainer(array $segments, array $fields, array $formData, array &$state, array $modified): array|string
    {
        $property = $segments[0];
        if (!isset($fields[$property])) {
            return \sprintf('unknown property "%s".', $property);
        }
        if ('block' !== ($fields[$property]['type'] ?? null)) {
            return \sprintf('"%s" is not a block property.', $property);
        }
        if (!isset($state[$property])) {
            $blocks = \is_array($formData[$property] ?? null) ? $formData[$property] : [];
            $state[$property] = \array_values($blocks);
        }

        $blocks = $state[$property];
        $blockTypes = $fields[$property]['blockTypes'] ?? [];
        $containerPath = $property;
        $descent = [];

        for ($i = 1; $i < \count($segments); $i += 2) {
            if (\in_array($containerPath, $modified, true)) {
                return \sprintf('the path descends through "%s", which was restructured by an earlier operation in this list; put edits inside a block list before restructuring it, or use a separate proposal.', $containerPath);
            }
            $indexSegment = $segments[$i];
            $child = $segments[$i + 1];
            if (!\ctype_digit($indexSegment)) {
                return \sprintf('"%s" is not a valid block index in "%s".', $indexSegment, $containerPath);
            }
            $index = (int) $indexSegment;
            if ($index >= \count($blocks)) {
                return \sprintf('block index %d is out of range for "%s" (%d blocks).', $index, $containerPath, \count($blocks));
            }
            $block = \is_array($blocks[$index]) ? $blocks[$index] : [];
            $blockType = (string) ($block['type'] ?? '');
            $typeFields = $blockTypes[$blockType]['fields'] ?? [];
            if (!isset($typeFields[$child])) {
                return \sprintf('block %d of "%s" is a "%s" block, which has no field "%s".', $index, $containerPath, $blockType, $child);
            }
            if ('block' !== ($typeFields[$child]['type'] ?? null)) {
                return \sprintf('"%s" of block %d of "%s" is not a nested block property.', $child, $index, $containerPath);
            }
            $blocks = \is_array($block[$child] ?? null) ? \array_values($block[$child]) : [];
            $blockTypes = $typeFields[$child]['blockTypes'] ?? [];
            $descent[] = [$index, $child];
            $containerPath .= '/' . $index . '/' . $child;
        }

        return ['path' => $containerPath, 'blocks' => $blocks, 'blockTypes' => $blockTypes, 'top' => $property, 'descent' => $descent];
    }

    /**
     * Applies $mutator to the block array reached by descending the
     * [index, childProperty] pairs below a top-level working array.
     *
     * @param array<int, mixed> $blocks
     * @param list<array{int, string}> $descent
     */
    private function mutateContainer(array &$blocks, array $descent, callable $mutator): void
    {
        if ([] === $descent) {
            $mutator($blocks);

            return;
        }
        [$index, $property] = \array_shift($descent);
        if (!\is_array($blocks[$index] ?? null)) {
            return; // resolveContainer already vetted the path
        }
        if (!\is_array($blocks[$index][$property] ?? null)) {
            $blocks[$index][$property] = [];
        }
        $this->mutateContainer($blocks[$index][$property], $descent, $mutator);
    }

    /**
     * @param array<string, mixed> $field
     */
    private function isEditableField(array $field): bool
    {
        return \in_array((string) ($field['type'] ?? ''), self::EDITABLE_FIELD_TYPES, true);
    }

    /**
     * A field value must be a scalar, null, or a flat list of scalars (e.g. a
     * multi-select). Nested objects/associative arrays are rejected so the
     * model cannot write a structure into a text field, which would corrupt the
     * form data and break the field's input on the client.
     */
    private function isValidFieldValue(mixed $value): bool
    {
        if (null === $value || \is_scalar($value)) {
            return true;
        }
        if (\is_array($value)) {
            if (!\array_is_list($value)) {
                return false;
            }
            foreach ($value as $item) {
                if (!\is_scalar($item)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
