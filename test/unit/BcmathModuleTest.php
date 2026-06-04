<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\bcmath\bcadd;
use PHPCompiler\ext\bcmath\VmBcmath;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * bcmath module registration and arithmetic (issues #5924, #5969).
 *
 * @group bcmath_module_skeleton
 */
final class BcmathModuleTest extends TestCase
{
    protected function setUp(): void
    {
        VmBcmath::scale(0);
    }

    public function test_bcmath_module_skeleton_functions(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['bcadd', 'bcsub', 'bcmul', 'bcdiv', 'bcscale', 'bccomp'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('bcadd');
echo (int) function_exists('bcsub');
echo (int) function_exists('bcmul');
echo (int) function_exists('bcdiv');
echo (int) function_exists('bcscale');
echo (int) function_exists('bccomp');
PHP;
        $block = $runtime->parseAndCompile($code, 'bcmath_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('111111', ob_get_clean());
    }

    public function test_bcadd_issue_5969(): void
    {
        $runtime = new Runtime();
        $fn = new bcadd();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VM\Variable();
        $left = new VM\Variable();
        $left->string('1.234');
        $right = new VM\Variable();
        $right->string('5');
        $scale = new VM\Variable();
        $scale->int(2);
        $frame->calledArgs = [$left, $right, $scale];

        $fn->execute($frame);

        self::assertSame('6.23', $frame->returnVar->resolveIndirect()->toString());
    }

    public function test_vmbcmath_core_ops(): void
    {
        self::assertSame('6.23', VmBcmath::add('1.234', '5', 2));
        self::assertSame('1.67', VmBcmath::sub('5.000', '3.333', 2));
        self::assertSame('6.25', VmBcmath::mul('2.5', '2.5', 2));
        self::assertSame('3.33', VmBcmath::div('10', '3', 2));
        self::assertSame(0, VmBcmath::comp('1.0', '1.00', 2));
        self::assertSame(1, VmBcmath::comp('1.01', '1.00', 2));
    }
}
