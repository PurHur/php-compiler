<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * posix extension module skeleton registration (issue #7105).
 *
 * @group posix_module_skeleton
 */
final class PosixModuleSkeletonTest extends TestCase
{
    public function test_posix_module_skeleton_functions_and_extension_loaded(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['posix_getpid', 'posix_getppid', 'posix_strerror', 'posix_get_last_error', 'posix_errno',
            'posix_access', 'posix_mknod', 'posix_setuid', 'posix_setgid', 'posix_seteuid', 'posix_setegid'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('posix_getpid');
echo (int) function_exists('posix_getppid');
echo (int) function_exists('posix_strerror');
echo (int) function_exists('posix_get_last_error');
echo (int) function_exists('posix_errno');
echo (int) function_exists('posix_access');
echo (int) function_exists('posix_mknod');
echo (int) function_exists('posix_setuid');
echo (int) function_exists('posix_setgid');
echo (int) function_exists('posix_seteuid');
echo (int) function_exists('posix_setegid');
echo (int) extension_loaded('posix');
PHP;
        $block = $runtime->parseAndCompile($code, 'posix_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('111111111111', ob_get_clean());
    }

    public function test_posix_getpid_returns_positive_int(): void
    {
        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\posix\posix_getpid();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new \PHPCompiler\VM\Variable();
        $fn->execute($frame);
        $this->assertGreaterThan(0, $frame->returnVar->resolveIndirect()->toInt());
    }
}
