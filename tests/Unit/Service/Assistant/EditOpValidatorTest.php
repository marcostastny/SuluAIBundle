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
