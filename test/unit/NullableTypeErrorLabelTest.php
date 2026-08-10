<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\DnfType;
use PHPUnit\Framework\TestCase;

/**
 * zend_type_to_string / TypeError: simple nullables print ?T (#29960).
 */
final class NullableTypeErrorLabelTest extends TestCase
{
    public function testFormatUnionTypeSimpleNullableUsesQuestionMark(): void
    {
        $arms = [
            ['kind' => 'literal', 'name' => 'int'],
            ['kind' => 'null'],
        ];
        $this->assertSame('?int', DnfType::formatUnionType($arms));
        $this->assertSame('?false', DnfType::zendTypeErrorLabel('false|null'));
        $this->assertSame('?string', DnfType::zendCanonicalUnionLabel(['null', 'string']));
    }

    public function testFormatUnionTypeKeepsMultiMemberPipeForm(): void
    {
        $arms = [
            ['kind' => 'literal', 'name' => 'int'],
            ['kind' => 'literal', 'name' => 'string'],
            ['kind' => 'null'],
        ];
        $this->assertSame('string|int|null', DnfType::formatUnionType($arms));
    }
}
