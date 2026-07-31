<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #15447 */
final class NonEnumBuiltinInterfaceCompileCheckTest extends TestCase
{
    public function testClassImplementsUnitEnumCompileFatal(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Non-enum class C cannot implement interface UnitEnum');
        $runtime->parseAndCompile(<<<'PHP'
<?php
class C implements UnitEnum {}
PHP, 'non_enum_unitenum.php');
    }

    public function testClassImplementsBackedEnumCompileFatal(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Non-enum class C cannot implement interface BackedEnum');
        $runtime->parseAndCompile(<<<'PHP'
<?php
class C implements BackedEnum {}
PHP, 'non_enum_backedenum.php');
    }

    /** @covers issue #25946 — was incorrectly allowed under #15447 */
    public function testBackedEnumImplementsUnitEnumFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Enum E cannot implement previously implemented interface UnitEnum');
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: int implements UnitEnum {
    case A = 1;
}
PHP, 'enum_unitenum.php');
    }
}
