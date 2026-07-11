<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\ext\standard\VmSession;
use PHPUnit\Framework\TestCase;

/**
 * session module skeleton registration (issue #6004).
 *
 * @group session_module_skeleton
 */
final class SessionModuleTest extends TestCase
{
    protected function tearDown(): void
    {
        VmSession::reset();
        parent::tearDown();
    }

    public function test_session_module_skeleton_functions(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (
            [
                'session_start',
                'session_id',
                'session_name',
                'session_status',
                'session_destroy',
                'session_write_close',
                'session_commit',
                'session_regenerate_id',
                'session_abort',
                'session_reset',
                'session_create_id',
                'session_encode',
                'session_decode',
                'session_unset',
                'session_gc',
            ] as $fn
        ) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('session_start');
echo (int) function_exists('session_id');
echo (int) function_exists('session_name');
echo (int) function_exists('session_status');
echo (int) function_exists('session_destroy');
echo (int) function_exists('session_write_close');
echo (int) function_exists('session_regenerate_id');
echo (int) function_exists('session_abort');
echo (int) function_exists('session_reset');
echo (int) function_exists('session_create_id');
echo (int) function_exists('session_encode');
echo (int) function_exists('session_decode');
echo (int) function_exists('session_unset');
echo (int) function_exists('session_gc');
echo PHP_SESSION_NONE, "\n";
echo PHP_SESSION_ACTIVE, "\n";
session_start();
echo session_name(), "\n";
session_write_close();
PHP;
        $block = $runtime->parseAndCompile($code, 'session_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("111111111111111\n2\nPHPSESSID\n", ob_get_clean());
    }
}
