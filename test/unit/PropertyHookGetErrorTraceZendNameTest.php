<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Get-hook Error getTrace must use Class->$prop::get (not __phpc_property_get_*) (#29689).
 */
final class PropertyHookGetErrorTraceZendNameTest extends TestCase
{
    public function testVmAndJitUseZendHookTraceFunctionName(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $src = <<<'PHP'
<?php
class C {
  public string $prop {
    get => $this->prop;
    set => $this->prop = $value;
  }
}
$c = new C;
try { echo $c->prop; } catch (Throwable $e) {
  echo $e->getTrace()[0]["class"], $e->getTrace()[0]["type"], $e->getTrace()[0]["function"], "\n";
}
PHP;
            $file = tempnam(sys_get_temp_dir(), 'phpc_hook_get_tr_');
            self::assertNotFalse($file);
            file_put_contents($file, $src);
            try {
                foreach (['bin/vm.php', 'bin/jit.php'] as $bin) {
                    $cmd = 'PHP_COMPILER_PROFILE=8.4 '
                        .escapeshellarg(PHP_BINARY).' '
                        .escapeshellarg(__DIR__.'/../../'.$bin).' '
                        .escapeshellarg($file).' 2>/dev/null';
                    $out = shell_exec($cmd);
                    self::assertIsString($out, $bin);
                    self::assertSame("C->\$prop::get\n", $out, $bin);
                    self::assertStringNotContainsString('__phpc_property_get_', $out, $bin);
                }
            } finally {
                @unlink($file);
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }

    /** Set-hook path from #29666 must still name TypeErrors Class::$prop::set(). */
    public function testSetHookTypeErrorStillUsesZendCallableName(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $file = __DIR__.'/../repro/issue_29666_property_hook_set_typeerror.php';
            $cmd = 'PHP_COMPILER_PROFILE=8.4 '
                .escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/vm.php').' '
                .escapeshellarg($file).' 2>/dev/null';
            $out = shell_exec($cmd);
            self::assertIsString($out);
            self::assertStringContainsString('C::$x::set(): Argument #1 ($v) must be of type int, string given', $out);
            self::assertStringNotContainsString('__phpc_property_set_', $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }
}
