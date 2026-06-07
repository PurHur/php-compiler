<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7052 */
final class NeverPropertyTypeTest extends TestCase
{
    public function testStandaloneNeverPropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public never $p;
}
echo "ok\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Property C::$p cannot have type never');
        $runtime->parseAndCompile($code, 'never_property.php');
    }

    public function testStaticNeverPropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public static never $p;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Property C::$p cannot have type never');
        $runtime->parseAndCompile($code, 'never_static_property.php');
    }

    public function testPromotedNeverPropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function __construct(public never $p) {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Property C::$p cannot have type never');
        $runtime->parseAndCompile($code, 'never_promoted_property.php');
    }
}
