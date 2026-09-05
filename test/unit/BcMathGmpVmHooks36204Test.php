<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\ext\bcmath\VmBcMathNumber;
use PHPCompiler\ext\gmp\VmGmpObject;
use PHPCompiler\VM\BcMathVmRuntimeSupport;
use PHPCompiler\VM\GmpVmRuntimeSupport;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * lib/VM/Variable.php must not import ext\bcmath / ext\gmp — hooks via VmRuntimeSupport (#36204).
 */
final class BcMathGmpVmHooks36204Test extends TestCase
{
    public function testLibVariableHasNoDirectExtImports(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/lib/VM/Variable.php');
        self::assertStringNotContainsString('PHPCompiler\\ext\\bcmath', $src);
        self::assertStringNotContainsString('PHPCompiler\\ext\\gmp', $src);
        self::assertStringContainsString('BcMathVmRuntimeSupport', $src);
        self::assertStringContainsString('GmpVmRuntimeSupport', $src);
    }

    public function testCompareHooksUnsetReturnNull(): void
    {
        BcMathVmRuntimeSupport::clear();
        GmpVmRuntimeSupport::clear();
        $a = new Variable(Variable::TYPE_INTEGER);
        $a->int(1);
        $b = new Variable(Variable::TYPE_INTEGER);
        $b->int(2);
        self::assertNull(BcMathVmRuntimeSupport::tryCompare($a, $b));
        self::assertNull(GmpVmRuntimeSupport::tryCompare($a, $b));
    }

    public function testCompareHooksDelegateWhenRegistered(): void
    {
        BcMathVmRuntimeSupport::clear();
        BcMathVmRuntimeSupport::setTryCompare(
            static fn ($left, $right) => VmBcMathNumber::tryCompare($left, $right)
        );
        GmpVmRuntimeSupport::clear();
        GmpVmRuntimeSupport::setTryCompare(
            static fn ($left, $right) => VmGmpObject::tryCompare($left, $right)
        );
        $a = new Variable(Variable::TYPE_INTEGER);
        $a->int(1);
        $b = new Variable(Variable::TYPE_INTEGER);
        $b->int(2);
        // Neither side is Number/GMP → hooks return null (same as direct SSOT).
        self::assertNull(BcMathVmRuntimeSupport::tryCompare($a, $b));
        self::assertNull(GmpVmRuntimeSupport::tryCompare($a, $b));
        BcMathVmRuntimeSupport::clear();
        GmpVmRuntimeSupport::clear();
    }

    public function testSupportClassesLoad(): void
    {
        self::assertTrue(class_exists(BcMathVmRuntimeSupport::class));
        self::assertTrue(class_exists(GmpVmRuntimeSupport::class));
    }
}
