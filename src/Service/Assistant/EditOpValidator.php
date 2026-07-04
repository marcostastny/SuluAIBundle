<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

/**
 * Validates assistant-proposed edit operations against the template schema and
 * the submitted form data. Indices refer to the state after previous ops in
 * the same list have been applied, so a running block count is tracked.
 */
class EditOpValidator
{
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
        $errors = [];

        // Running block types per block property, mutated by structural ops.
        $blockStates = [];
        foreach ($fields as $name => $field) {
            if ('block' === ($field['type'] ?? null)) {
                $blocks = \is_array($formData[$name] ?? null) ? $formData[$name] : [];
                $blockStates[$name] = \array_map(
                    static fn ($block) => \is_array($block) ? (string) ($block['type'] ?? '') : '',
                    \array_values($blocks)
                );
            }
        }

        foreach ($ops as $i => $op) {
            $error = $this->validateOp(\is_array($op) ? $op : [], $fields, $blockStates);
            if (null !== $error) {
                $errors[] = \sprintf('op %d: %s', $i, $error);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $op
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, list<string>> $blockStates
     */
    private function validateOp(array $op, array $fields, array &$blockStates): ?string
    {
        $kind = (string) ($op['op'] ?? '');
        $path = (string) ($op['path'] ?? '');

        switch ($kind) {
            case 'set':
                if (!\preg_match('#^/([^/]+)$#', $path, $matches)) {
                    return \sprintf('"%s" is not a valid set path, expected "/<property>".', $path);
                }
                $property = $matches[1];
                if (!isset($fields[$property])) {
                    return \sprintf('unknown property "%s".', $property);
                }
                if ('block' === $fields[$property]['type']) {
                    return \sprintf('"%s" is a block property; use setBlockField/insertBlock/removeBlock/moveBlock.', $property);
                }
                if (!$this->isValidFieldValue($op['value'] ?? null)) {
                    return \sprintf('value for "%s" must be a scalar or a list of scalars.', $property);
                }

                return null;

            case 'setBlockField':
                if (!\preg_match('#^/([^/]+)/(\d+)/([^/]+)$#', $path, $matches)) {
                    return \sprintf('"%s" is not a valid setBlockField path, expected "/<blockProperty>/<index>/<field>".', $path);
                }
                [, $property, $index, $blockField] = $matches;
                $index = (int) $index;
                if (null !== $error = $this->checkBlockProperty($property, $fields, $blockStates)) {
                    return $error;
                }
                if ($index >= \count($blockStates[$property])) {
                    return \sprintf('block index %d is out of range for "%s" (%d blocks).', $index, $property, \count($blockStates[$property]));
                }
                $blockType = $blockStates[$property][$index];
                $typeFields = $fields[$property]['blockTypes'][$blockType]['fields'] ?? [];
                if (!isset($typeFields[$blockField])) {
                    return \sprintf('block %d of "%s" is a "%s" block, which has no field "%s".', $index, $property, $blockType, $blockField);
                }
                if (!$this->isValidFieldValue($op['value'] ?? null)) {
                    return \sprintf('value for "%s/%d/%s" must be a scalar or a list of scalars.', $property, $index, $blockField);
                }

                return null;

            case 'insertBlock':
                if (null !== $error = $this->checkBlockPath($path, $fields, $blockStates, $property)) {
                    return $error;
                }
                $index = (int) ($op['index'] ?? -1);
                if ($index < 0 || $index > \count($blockStates[$property])) {
                    return \sprintf('insert index %d is out of range for "%s" (0..%d).', $index, $property, \count($blockStates[$property]));
                }
                $block = \is_array($op['block'] ?? null) ? $op['block'] : [];
                $blockType = (string) ($block['type'] ?? '');
                $blockTypes = $fields[$property]['blockTypes'] ?? [];
                if (!isset($blockTypes[$blockType])) {
                    return \sprintf('unknown block type "%s"; available: %s.', $blockType, \implode(', ', \array_keys($blockTypes)));
                }
                foreach (\array_keys($block) as $key) {
                    if ('type' === $key || 'settings' === $key) {
                        continue;
                    }
                    if (!isset($blockTypes[$blockType]['fields'][$key])) {
                        return \sprintf('block type "%s" has no field "%s".', $blockType, $key);
                    }
                }
                \array_splice($blockStates[$property], $index, 0, [$blockType]);

                return null;

            case 'removeBlock':
                if (null !== $error = $this->checkBlockPath($path, $fields, $blockStates, $property)) {
                    return $error;
                }
                $index = (int) ($op['index'] ?? -1);
                if ($index < 0 || $index >= \count($blockStates[$property])) {
                    return \sprintf('remove index %d is out of range for "%s" (%d blocks).', $index, $property, \count($blockStates[$property]));
                }
                \array_splice($blockStates[$property], $index, 1);

                return null;

            case 'moveBlock':
                if (null !== $error = $this->checkBlockPath($path, $fields, $blockStates, $property)) {
                    return $error;
                }
                $from = (int) ($op['from'] ?? -1);
                $to = (int) ($op['to'] ?? -1);
                $count = \count($blockStates[$property]);
                if ($from < 0 || $from >= $count || $to < 0 || $to >= $count) {
                    return \sprintf('move %d -> %d is out of range for "%s" (%d blocks).', $from, $to, $property, $count);
                }
                $moved = \array_splice($blockStates[$property], $from, 1);
                \array_splice($blockStates[$property], $to, 0, $moved);

                return null;

            default:
                return \sprintf('unknown op "%s".', $kind);
        }
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

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, list<string>> $blockStates
     */
    private function checkBlockPath(string $path, array $fields, array $blockStates, ?string &$property): ?string
    {
        if (!\preg_match('#^/([^/]+)$#', $path, $matches)) {
            return \sprintf('"%s" is not a valid block path, expected "/<blockProperty>".', $path);
        }
        $property = $matches[1];

        return $this->checkBlockProperty($property, $fields, $blockStates);
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, list<string>> $blockStates
     */
    private function checkBlockProperty(string $property, array $fields, array $blockStates): ?string
    {
        if (!isset($fields[$property])) {
            return \sprintf('unknown property "%s".', $property);
        }
        if ('block' !== $fields[$property]['type'] || !isset($blockStates[$property])) {
            return \sprintf('"%s" is not a block property.', $property);
        }

        return null;
    }
}
