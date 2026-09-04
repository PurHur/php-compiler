<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT slices for Composer/Slim (#36382): set_error_handler(static closure) +
 * stream_wrapper_register(..., get_class(new class {})).
 *
 * @group llvm
 */
final class Issue36382SetErrorHandlerStaticClosureAotTest extends TestCase
{
    public function testAotBinaryThrowsViaStaticClosureHandler(): void
    {
        $src = <<<'PHP'
        <?php
        $exception = null;
        set_error_handler(static function ($type, $message) use (&$exception) {
            throw $exception = new RuntimeException('Unable to read stream contents: ' . $message);
        });
        try {
            trigger_error('boom', E_USER_WARNING);
            echo "no-throw\n";
        } catch (Throwable $e) {
            echo $e->getMessage(), "\n";
        } finally {
            restore_error_handler();
        }
        PHP;
        $path = sys_get_temp_dir().'/phpc_36382_seh_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_36382_seh_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(['Unable to read stream contents: boom'], $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testAotStreamWrapperRegisterAcceptsGetClassOfAnonymous(): void
    {
        $src = <<<'PHP'
        <?php
        static $wrapper;
        $ok = $wrapper ?? stream_wrapper_register('Nyholm-Psr7-Zval', $wrapper = get_class(new class() {
            public $context;
            public function stream_open(): bool { return true; }
            public function stream_read(int $count): string { return ''; }
            public function stream_eof(): bool { return true; }
            public function stream_stat(): array { return []; }
        }));
        echo $ok ? "ok\n" : "fail\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_36382_swr_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_36382_swr_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(['ok'], $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }
}
