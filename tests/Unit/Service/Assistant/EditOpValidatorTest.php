<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant;

use Marcostastny\SuluAIBundle\Service\Assistant\EditOpValidator;
use PHPUnit\Framework\TestCase;

class EditOpValidatorTest extends TestCase
{
    private EditOpValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new EditOpValidator();
    }

    /**
     * @return array{fields: array<string, array<string, mixed>>}
     */
    private function schema(): array
    {
        return [
            'fields' => [
                'title' => ['type' => 'text_line', 'required' => true],
                'article' => ['type' => 'text_editor', 'required' => false],
                'blocks' => [
                    'type' => 'block',
                    'required' => false,
                    'defaultType' => 'textBlock',
                    'blockTypes' => [
                        'textBlock' => ['fields' => ['text' => ['type' => 'text_editor', 'required' => false]]],
                        'quote' => ['fields' => [
                            'quote' => ['type' => 'text_line', 'required' => false],
                            'author' => ['type' => 'text_line', 'required' => false],
                        ]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'title' => 'Old title',
            'blocks' => [
                ['type' => 'textBlock', 'text' => '<p>one</p>'],
                ['type' => 'quote', 'quote' => 'q', 'author' => 'a'],
            ],
        ];
    }

    public function testValidOpsOfEveryKindPass(): void
    {
        $ops = [
            ['op' => 'set', 'path' => '/title', 'value' => 'New title'],
            ['op' => 'setBlockField', 'path' => '/blocks/0/text', 'value' => '<p>new</p>'],
            ['op' => 'insertBlock', 'path' => '/blocks', 'index' => 2, 'block' => ['type' => 'quote', 'quote' => 'x']],
            ['op' => 'moveBlock', 'path' => '/blocks', 'from' => 0, 'to' => 2],
            ['op' => 'removeBlock', 'path' => '/blocks', 'index' => 1],
        ];

        $this->assertSame([], $this->validator->validate($ops, $this->schema(), $this->formData()));
    }

    public function testSetRejectsNonScalarValue(): void
    {
        $errors = $this->validator->validate(
            [['op' => 'set', 'path' => '/title', 'value' => ['de' => 'x']]],
            $this->schema(),
            $this->formData()
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('scalar', $errors[0]);
    }

    public function testSetBlockFieldRejectsNonScalarValue(): void
    {
        $errors = $this->validator->validate(
            [['op' => 'setBlockField', 'path' => '/blocks/0/text', 'value' => ['nested' => true]]],
            $this->schema(),
            $this->formData()
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('scalar', $errors[0]);
    }

    public function testSetAcceptsScalarAndListValues(): void
    {
        $errors = $this->validator->validate(
            [
                ['op' => 'set', 'path' => '/title', 'value' => 'plain'],
                ['op' => 'set', 'path' => '/article', 'value' => ['a', 'b']],
            ],
            $this->schema(),
            $this->formData()
        );

        $this->assertSame([], $errors);
    }

    public function testSetRejectsScalarIntoComplexSelectionField(): void
    {
        $schema = ['fields' => [
            'image' => ['type' => 'media_selection', 'required' => false],
        ]];

        $errors = $this->validator->validate(
            [['op' => 'set', 'path' => '/image', 'value' => 'sunset']],
            $schema,
            []
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('cannot edit', $errors[0]);
    }

    public function testInsertBlockRejectsScalarIntoComplexSubField(): void
    {
        $schema = ['fields' => [
            'content' => [
                'type' => 'block',
                'required' => false,
                'blockTypes' => [
                    'gallery' => ['fields' => ['images' => ['type' => 'media_selection']]],
                ],
            ],
        ]];

        $errors = $this->validator->validate(
            [['op' => 'insertBlock', 'path' => '/content', 'index' => 0, 'block' => ['type' => 'gallery', 'images' => 'x']]],
            $schema,
            ['content' => []]
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('cannot set', $errors[0]);
    }

    public function testInsertRaisesTheValidIndexRangeForLaterOps(): void
    {
        // formData has 2 blocks; after the insert, index 2 is a valid target.
        $ops = [
            ['op' => 'insertBlock', 'path' => '/blocks', 'index' => 0, 'block' => ['type' => 'textBlock']],
            ['op' => 'setBlockField', 'path' => '/blocks/2/quote', 'value' => 'updated'],
        ];

        $this->assertSame([], $this->validator->validate($ops, $this->schema(), $this->formData()));
    }

    public function testUnknownOpFails(): void
    {
        $errors = $this->validator->validate([['op' => 'explode']], $this->schema(), $this->formData());

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('unknown op', $errors[0]);
    }

    public function testSetUnknownPropertyFails(): void
    {
        $errors = $this->validator->validate(
            [['op' => 'set', 'path' => '/nonexistent', 'value' => 'x']],
            $this->schema(),
            $this->formData()
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('nonexistent', $errors[0]);
    }

    public function testSetOnBlockPropertyFails(): void
    {
        $errors = $this->validator->validate(
            [['op' => 'set', 'path' => '/blocks', 'value' => []]],
            $this->schema(),
            $this->formData()
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('block', $errors[0]);
    }

    public function testSetBlockFieldWithOutOfRangeIndexFails(): void
    {
        $errors = $this->validator->validate(
            [['op' => 'setBlockField', 'path' => '/blocks/5/text', 'value' => 'x']],
            $this->schema(),
            $this->formData()
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('index', $errors[0]);
    }

    public function testSetBlockFieldUnknownFieldForActualBlockTypeFails(): void
    {
        // Block 0 is a textBlock; "author" only exists on quote blocks.
        $errors = $this->validator->validate(
            [['op' => 'setBlockField', 'path' => '/blocks/0/author', 'value' => 'x']],
            $this->schema(),
            $this->formData()
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('author', $errors[0]);
    }

    public function testInsertBlockWithUnknownTypeFails(): void
    {
        $errors = $this->validator->validate(
            [['op' => 'insertBlock', 'path' => '/blocks', 'index' => 0, 'block' => ['type' => 'video']]],
            $this->schema(),
            $this->formData()
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('video', $errors[0]);
    }

    public function testInsertBlockWithUnknownInnerFieldFails(): void
    {
        $errors = $this->validator->validate(
            [['op' => 'insertBlock', 'path' => '/blocks', 'index' => 0, 'block' => ['type' => 'textBlock', 'caption' => 'x']]],
            $this->schema(),
            $this->formData()
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('caption', $errors[0]);
    }

    public function testInsertBlockIndexBeyondEndFails(): void
    {
        $errors = $this->validator->validate(
            [['op' => 'insertBlock', 'path' => '/blocks', 'index' => 3, 'block' => ['type' => 'textBlock']]],
            $this->schema(),
            $this->formData()
        );

        $this->assertCount(1, $errors);
    }

    public function testRemoveBlockOutOfRangeFails(): void
    {
        $errors = $this->validator->validate(
            [['op' => 'removeBlock', 'path' => '/blocks', 'index' => 2]],
            $this->schema(),
            $this->formData()
        );

        $this->assertCount(1, $errors);
    }

    public function testMoveBlockTargetOutOfRangeFails(): void
    {
        $errors = $this->validator->validate(
            [['op' => 'moveBlock', 'path' => '/blocks', 'from' => 0, 'to' => 4]],
            $this->schema(),
            $this->formData()
        );

        $this->assertCount(1, $errors);
    }

    public function testSetAcceptsPropertyNamesContainingSlashes(): void
    {
        $schema = ['fields' => [
            'seo/title' => ['type' => 'text_line', 'required' => false],
            'seo/description' => ['type' => 'text_area', 'required' => false],
        ]];

        $errors = $this->validator->validate([
            ['op' => 'set', 'path' => '/seo/title', 'value' => 'Hotel Kulm — Zimmer'],
            ['op' => 'set', 'path' => '/seo/description', 'value' => 'Beschreibung'],
        ], $schema, []);

        $this->assertSame([], $errors);
    }

    public function testSetRejectsUnknownSlashedProperty(): void
    {
        $schema = ['fields' => ['seo/title' => ['type' => 'text_line', 'required' => false]]];

        $errors = $this->validator->validate(
            [['op' => 'set', 'path' => '/seo/nonexistent', 'value' => 'x']],
            $schema,
            []
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('seo/nonexistent', $errors[0]);
    }

    /**
     * Schema with two-level nesting: blocks → infoCards → cards → card → rows → row.
     *
     * @return array{fields: array<string, array<string, mixed>>}
     */
    private function nestedSchema(): array
    {
        return [
            'fields' => [
                'title' => ['type' => 'text_line', 'required' => true],
                'blocks' => [
                    'type' => 'block',
                    'required' => false,
                    'defaultType' => 'infoCards',
                    'blockTypes' => [
                        'textBlock' => ['fields' => ['text' => ['type' => 'text_editor', 'required' => false]]],
                        'infoCards' => ['fields' => [
                            'title' => ['type' => 'text_line', 'required' => false],
                            'cards' => [
                                'type' => 'block',
                                'required' => false,
                                'defaultType' => 'card',
                                'blockTypes' => [
                                    'card' => ['fields' => [
                                        'head' => ['type' => 'text_line', 'required' => false],
                                        'rows' => [
                                            'type' => 'block',
                                            'required' => false,
                                            'defaultType' => 'row',
                                            'blockTypes' => [
                                                'row' => ['fields' => [
                                                    'label' => ['type' => 'text_line', 'required' => false],
                                                    'value' => ['type' => 'text_line', 'required' => false],
                                                ]],
                                            ],
                                        ],
                                    ]],
                                ],
                            ],
                        ]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nestedFormData(): array
    {
        return [
            'title' => 'Zimmer & Preise',
            'blocks' => [
                ['type' => 'textBlock', 'text' => '<p>intro</p>'],
                ['type' => 'infoCards', 'title' => 'Gut zu wissen', 'cards' => [
                    ['type' => 'card', 'head' => 'Check-in', 'rows' => [
                        ['type' => 'row', 'label' => 'Kurtaxe p. P. / Nacht', 'value' => 'CHF 2.50'],
                        ['type' => 'row', 'label' => 'Check-out', 'value' => '11:00'],
                    ]],
                ]],
            ],
        ];
    }

    public function testNestedSetBlockFieldPasses(): void
    {
        $ops = [['op' => 'setBlockField', 'path' => '/blocks/1/cards/0/rows/0/value', 'value' => 'CHF 3.50']];

        $this->assertSame([], $this->validator->validate($ops, $this->nestedSchema(), $this->nestedFormData()));
    }

    public function testNestedSetBlockFieldRejectsUnknownField(): void
    {
        $errors = $this->validator->validate(
            [['op' => 'setBlockField', 'path' => '/blocks/1/cards/0/rows/0/price', 'value' => 'x']],
            $this->nestedSchema(),
            $this->nestedFormData()
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('"row" block, which has no field "price"', $errors[0]);
    }

    public function testNestedSetBlockFieldRejectsOutOfRangeInnerIndex(): void
    {
        $errors = $this->validator->validate(
            [['op' => 'setBlockField', 'path' => '/blocks/1/cards/0/rows/9/value', 'value' => 'x']],
            $this->nestedSchema(),
            $this->nestedFormData()
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('out of range for "blocks/1/cards/0/rows"', $errors[0]);
    }

    public function testNestedSetBlockFieldRejectsDescentThroughNonBlockField(): void
    {
        $errors = $this->validator->validate(
            [['op' => 'setBlockField', 'path' => '/blocks/1/title/0/value', 'value' => 'x']],
            $this->nestedSchema(),
            $this->nestedFormData()
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('not a nested block property', $errors[0]);
    }

    public function testNestedStructuralOpsPass(): void
    {
        $ops = [
            ['op' => 'insertBlock', 'path' => '/blocks/1/cards/0/rows', 'index' => 2, 'block' => ['type' => 'row', 'label' => 'Hunde', 'value' => 'CHF 10']],
            ['op' => 'moveBlock', 'path' => '/blocks/1/cards/0/rows', 'from' => 2, 'to' => 0],
            ['op' => 'removeBlock', 'path' => '/blocks/1/cards/0/rows', 'index' => 1],
        ];

        $this->assertSame([], $this->validator->validate($ops, $this->nestedSchema(), $this->nestedFormData()));
    }

    public function testNestedInsertTracksRunningIndices(): void
    {
        // After the insert, rows has 3 entries — index 2 is a valid target.
        $ops = [
            ['op' => 'setBlockField', 'path' => '/blocks/1/cards/0/rows/1/value', 'value' => '12:00'],
            ['op' => 'insertBlock', 'path' => '/blocks/1/cards/0/rows', 'index' => 0, 'block' => ['type' => 'row']],
            ['op' => 'removeBlock', 'path' => '/blocks/1/cards/0/rows', 'index' => 2],
        ];

        $this->assertSame([], $this->validator->validate($ops, $this->nestedSchema(), $this->nestedFormData()));
    }

    public function testInsertBlockWithNestedBlockContentPasses(): void
    {
        $ops = [['op' => 'insertBlock', 'path' => '/blocks/1/cards', 'index' => 1, 'block' => [
            'type' => 'card',
            'head' => 'Anreise',
            'rows' => [
                ['type' => 'row', 'label' => 'Check-in', 'value' => '14:00'],
                ['type' => 'row', 'label' => 'Parkplatz', 'value' => 'kostenlos'],
            ],
        ]]];

        $this->assertSame([], $this->validator->validate($ops, $this->nestedSchema(), $this->nestedFormData()));
    }

    public function testInsertBlockRejectsInvalidNestedBlockContent(): void
    {
        $errors = $this->validator->validate(
            [['op' => 'insertBlock', 'path' => '/blocks/1/cards', 'index' => 0, 'block' => [
                'type' => 'card',
                'rows' => [['type' => 'row', 'price' => 'CHF 1']],
            ]]],
            $this->nestedSchema(),
            $this->nestedFormData()
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('block type "row" has no field "price"', $errors[0]);
    }

    public function testOpDescendingThroughRestructuredContainerIsRejected(): void
    {
        $ops = [
            ['op' => 'insertBlock', 'path' => '/blocks', 'index' => 0, 'block' => ['type' => 'textBlock', 'text' => 'x']],
            ['op' => 'setBlockField', 'path' => '/blocks/2/cards/0/rows/0/value', 'value' => 'CHF 3.50'],
        ];

        $errors = $this->validator->validate($ops, $this->nestedSchema(), $this->nestedFormData());

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('restructured by an earlier operation', $errors[0]);
    }

    public function testInnerEditBeforeAncestorRestructureIsAccepted(): void
    {
        $ops = [
            ['op' => 'setBlockField', 'path' => '/blocks/1/cards/0/rows/0/value', 'value' => 'CHF 3.50'],
            ['op' => 'insertBlock', 'path' => '/blocks', 'index' => 0, 'block' => ['type' => 'textBlock', 'text' => 'x']],
        ];

        $this->assertSame([], $this->validator->validate($ops, $this->nestedSchema(), $this->nestedFormData()));
    }

    public function testErrorsArePrefixedWithOpIndex(): void
    {
        $errors = $this->validator->validate(
            [
                ['op' => 'set', 'path' => '/title', 'value' => 'ok'],
                ['op' => 'set', 'path' => '/bad', 'value' => 'x'],
            ],
            $this->schema(),
            $this->formData()
        );

        $this->assertCount(1, $errors);
        $this->assertStringStartsWith('op 1:', $errors[0]);
    }
}
