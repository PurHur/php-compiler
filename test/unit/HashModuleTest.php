<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * hash extension incremental HashContext (issues #6937, #7174).
 *
 * @group hash_module_skeleton
 */
final class HashModuleTest extends TestCase
{
    public function test_hash_module_skeleton_functions(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['hash_init', 'hash_update', 'hash_update_stream', 'hash_final', 'hash_copy', 'hash_algos', 'hash_file', 'hash_hmac_file'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('hash_init');
echo (int) function_exists('hash_update');
echo (int) function_exists('hash_update_stream');
echo (int) function_exists('hash_final');
echo (int) function_exists('hash_copy');
echo (int) function_exists('hash_algos');
echo (int) function_exists('hash_file');
echo (int) function_exists('hash_hmac_file');
echo (int) class_exists('HashContext');
$path = sys_get_temp_dir() . '/hash_mod_test.txt';
file_put_contents($path, 'hello');
echo hash_file('sha256', $path), "\n";
unlink($path);
PHP;
        $block = $runtime->parseAndCompile($code, 'hash_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            '1111111112cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824'."\n",
            ob_get_clean()
        );
    }

    public function test_hash_context_incremental_vm(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$ctx = hash_init('sha256');
hash_update($ctx, 'hello ');
hash_update($ctx, 'world');
echo hash_final($ctx);
PHP;
        $block = $runtime->parseAndCompile($code, 'hash_context.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            'b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9',
            ob_get_clean()
        );
    }

    public function test_hash_init_hmac_and_reflection(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$rf = new ReflectionFunction('hash_init');
echo $rf->getNumberOfParameters(), "\n";
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), "\n";
}
echo defined('HASH_HMAC') ? HASH_HMAC : 'missing', "\n";
$c = hash_init('sha256', HASH_HMAC, 'secret');
hash_update($c, 'msg');
echo hash_final($c), "\n";
$c2 = hash_init(algo: 'md5', flags: 0);
hash_update($c2, 'x');
echo hash_final($c2), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'hash_init_hmac.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "4\nalgo\nflags\nkey\noptions\n1\n"
            .'fe4f9c418f683f034f6af90d1dd5b86ac0355dd96332c59cc74598d0736107f6'."\n"
            .'9dd4e461268c8034f5c8564e155c67a6'."\n",
            ob_get_clean()
        );
    }

    public function test_hash_init_invalid_algo_value_error(): void
    {
        $runtime = new Runtime();
        (new \PHPCompiler\ext\hash\Module())->init($runtime);
        $fn = new \PHPCompiler\ext\hash\hash_init();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->vmContext = $runtime->vmContext;
        $algo = new VM\Variable();
        $algo->string('nope');
        $frame->calledArgs = [$algo];

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('hash_init(): Argument #1 ($algo) must be a valid hashing algorithm');
        $fn->execute($frame);
    }

    public function test_hash_update_stream_memory_round_trip(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$h = fopen('php://memory', 'r+');
fwrite($h, 'hello world');
rewind($h);
$ctx = hash_init('sha256');
$n = hash_update_stream($ctx, $h);
echo $n, "\n";
echo hash_final($ctx), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'hash_update_stream.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "11\nb94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9\n",
            ob_get_clean()
        );
    }

    public function test_hash_context_is_final(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
hash_init('sha256');
echo (new ReflectionClass(HashContext::class))->isFinal() ? '1' : '0';
PHP;
        $block = $runtime->parseAndCompile($code, 'hash_context_is_final.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('1', ob_get_clean());
    }

    public function test_hash_context_debug_info_withheld_on_reference_profile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$ctx = hash_init('sha256');
echo method_exists($ctx, '__debugInfo') ? '1' : '0';
PHP;
        $block = $runtime->parseAndCompile($code, 'hash_context_debug_info_ref.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('0', ob_get_clean());
    }

    public function test_hash_context_debug_info_on_forward_profile_84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
$ctx = hash_init('sha256');
$info = $ctx->__debugInfo();
echo (int) is_array($info);
echo isset($info['algo']) ? $info['algo'] : '';
PHP;
            $block = $runtime->parseAndCompile($code, 'hash_context_debug_info.php');
            ob_start();
            $runtime->run($block);
            self::assertSame('1sha256', ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
