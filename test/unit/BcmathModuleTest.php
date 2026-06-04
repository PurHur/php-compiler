<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\bcmath\bcadd;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * bcmath module skeleton registration (issue #5924).
 *
 * @group bcmath_module_skeleton
 */
final class BcmathModuleTest extends TestCase
{
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

    public function test_bcadd_stub_documents_issue_3365(): void
    {
        $stub = new bcadd();
        $frame = new Frame($stub, null, null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('#3365');
        $this->expectExceptionMessage('bcadd()');

        $stub->execute($frame);
    }
}
