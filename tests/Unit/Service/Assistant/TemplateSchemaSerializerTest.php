<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant;

use Marcostastny\SuluAIBundle\Service\Assistant\TemplateSchemaSerializer;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;

class TemplateSchemaSerializerTest extends TestCase
{
    public function testSerializeFlattensSectionsAndSerializesBlockTypes(): void
    {
        $title = new FieldMetadata('title');
        $title->setType('text_line');
        $title->setRequired(true);

        $sectionField = new FieldMetadata('subtitle');
        $sectionField->setType('text_line');
        $section = new SectionMetadata('intro');
        $section->addItem($sectionField);

        $text = new FieldMetadata('text');
        $text->setType('text_editor');
        $textBlock = new FormMetadata();
        $textBlock->setKey('textBlock');
        $textBlock->addItem($text);

        $blocks = new FieldMetadata('blocks');
        $blocks->setType('block');
        $blocks->setDefaultType('textBlock');
        $blocks->addType($textBlock);

        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($title);
        $form->addItem($section);
        $form->addItem($blocks);

        $schema = (new TemplateSchemaSerializer())->serialize($form);

        $this->assertSame(
            [
                'fields' => [
                    'title' => ['type' => 'text_line', 'required' => true],
                    'subtitle' => ['type' => 'text_line', 'required' => false],
                    'blocks' => [
                        'type' => 'block',
                        'required' => false,
                        'defaultType' => 'textBlock',
                        'blockTypes' => [
                            'textBlock' => [
                                'fields' => [
                                    'text' => ['type' => 'text_editor', 'required' => false],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            $schema
        );
    }
}
