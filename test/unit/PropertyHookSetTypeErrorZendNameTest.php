<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Set-hook TypeError must use Class::$prop::set() (not __phpc_property_set_*) (#29666).
 */
final class PropertyHookSetTypeErrorZendNameTest extends TestCase
{
    public function testVmAndJitUseZendHookCallableNameAndAssignLine(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $src = <<<'PHP'
<?php
class C {
  public int $x {
    set (int $v) { $this->x = $v; }
  }
}
$o = new C();
try { $o->x = "nope"; echo "SET"; } catch (TypeError $e) { echo $e->getMessage(); }
PHP;
            $file = tempnam(sys_get_temp_dir(), 'phpc_hook_te_');
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
                    self::assertStringContainsString('C::$x::set(): Argument #1 ($v) must be of type int, string given', $out, $bin);
                    self::assertStringNotContainsString('__phpc_property_set_', $out, $bin);
                    self::assertMatchesRegularExpression('/called in .+ on line 8\b/', $out, $bin);
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
}
