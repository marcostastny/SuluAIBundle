<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;

/**
 * Serializes a page template's FormMetadata into a compact array shared by the
 * assistant prompt, the edit-op validator and the model.
 */
class TemplateSchemaSerializer
{
    /**
     * @return array{fields: array<string, array<string, mixed>>}
     */
    public function serialize(FormMetadata $form): array
    {
        return ['fields' => $this->serializeItems($form->getItems())];
    }

    /**
     * @param mixed[] $items
     *
     * @return array<string, array<string, mixed>>
     */
    private function serializeItems(array $items): array
    {
        $fields = [];
        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                $fields = \array_merge($fields, $this->serializeItems($item->getItems()));

                continue;
            }

            if (!$item instanceof FieldMetadata) {
                continue;
            }

            $field = [
                'type' => $item->getType(),
                'required' => $item->isRequired(),
            ];

            $blockTypes = $item->getTypes();
            if ('block' === $item->getType() && [] !== $blockTypes) {
                $field['defaultType'] = $item->getDefaultType();
                $field['blockTypes'] = [];
                foreach ($blockTypes as $key => $typeForm) {
                    $field['blockTypes'][$key] = ['fields' => $this->serializeItems($typeForm->getItems())];
                }
            }

            $fields[$item->getName()] = $field;
        }

        return $fields;
    }
}
