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

    /** @covers issue #26382 */
    public function testUnitEnumCaseWithValueFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Case A of non-backed enum E must not have a value');
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E {
    case A = 1;
}
echo "should not run\n";
PHP,
            'enum_unit_with_value.php'
        );
    }

    /** @covers issue #26382 */
    public function testUnitEnumCaseWithStringValueFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Case A of non-backed enum E must not have a value');
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E {
    case A = 'A';
}
echo "should not run\n";
PHP,
            'enum_unit_with_string_value.php'
        );
    }

    /** @covers issue #26382 */
    public function testUnitEnumWithBareConstStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E {
    case A;
    const X = 1;
}
echo E::X;
PHP,
            'enum_unit_bare_const.php'
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

    public function testIntBackedDuplicateBackingValueThrowsErrorAtFirstUse(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: int {
    case A = 1;
    case B = 1;
}
try {
    echo E::A->name, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP,
            'enum_dup_int.php'
        );
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();
        $this->assertSame("Error: Duplicate value in enum E for cases A and B\n", $output);
    }

    public function testStringBackedDuplicateBackingValueThrowsErrorAtFirstUse(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: string {
    case A = 'x';
    case B = 'x';
}
try {
    echo E::A->name, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP,
            'enum_dup_string.php'
        );
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();
        $this->assertSame("Error: Duplicate value in enum E for cases A and B\n", $output);
    }

    public function testUnitEnumDuplicateCaseNameFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot redefine class constant E::A');
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E {
    case A;
    case A;
}
PHP,
            'enum_dup_case.php'
        );
    }

    public function testBackedEnumDuplicateCaseNameFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot redefine class constant E::A');
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: string {
    case A = 'x';
    case A = 'y';
}
PHP,
            'enum_dup_case_backed.php'
        );
    }

    /** @covers issue #25929 */
    public function testUnitEnumCaseDifferingNamesCompile(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E {
    case A;
    case a;
}
foreach (E::cases() as $c) {
    echo $c->name, ' ';
}
echo "\n";
echo E::A === E::a ? "same\n" : "diff\n";
PHP,
            'enum_case_differ.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("A a \ndiff\n", ob_get_clean());
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
