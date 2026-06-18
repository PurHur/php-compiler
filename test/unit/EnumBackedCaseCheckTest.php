<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5397 */
final class EnumBackedCaseCheckTest extends TestCase
{
    public function testStringBackedEnumCaseWithoutValueFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Enum case E::A must have a value');
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: string {
    case A;
}
PHP,
            'enum_backed_no_value.php'
        );
    }

    public function testIntBackedEnumCaseWithoutValueFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Enum case E::A must have a value');
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: int {
    case A;
}
PHP,
            'enum_backed_no_value_int.php'
        );
    }

    public function testUnitEnumCaseWithoutValueStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E {
    case A;
}
echo E::A->name;
PHP,
            'enum_unit.php'
        );
        $this->assertNotNull($block);
    }

    public function testBackedEnumCaseWithValueCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: string {
    case A = 'x';
}
echo E::A->value;
PHP,
            'enum_backed_with_value.php'
        );
        $this->assertNotNull($block);
    }

    public function testIntBackedEnumCaseWithValueCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: int {
    case A = 1;
}
echo E::A->value;
PHP,
            'enum_backed_int_value.php'
        );
        $this->assertNotNull($block);
    }

    public function testStringBackedEnumCaseWithSameNameValueCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: string {
    case A = 'A';
}
echo E::A->value;
PHP,
            'enum_backed_same_name_value.php'
        );
        $this->assertNotNull($block);
    }

    public function testIntBackedDuplicateBackingValueCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: int {
    case A = 1;
    case B = 1;
}
PHP,
            'enum_dup_int.php'
        );
        $this->assertNotNull($block);
    }

    public function testStringBackedDuplicateBackingValueCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: string {
    case A = 'x';
    case B = 'x';
}
PHP,
            'enum_dup_string.php'
        );
        $this->assertNotNull($block);
    }

    public function testDuplicateBackingErrorMessage(): void
    {
        $message = \PHPCompiler\Compiler\EnumBackedCaseCheck::duplicateBackingErrorMessage('E', [
            ['name' => 'A', 'backing' => 1],
            ['name' => 'B', 'backing' => 1],
        ]);
        $this->assertSame('Duplicate value in enum E for cases A and B', $message);

        $this->assertNull(\PHPCompiler\Compiler\EnumBackedCaseCheck::duplicateBackingErrorMessage('F', [
            ['name' => 'A', 'backing' => 1],
            ['name' => 'B', 'backing' => 2],
        ]));
    }
}
