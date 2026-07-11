<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Chained concat with float builtins — second operand must not read uninitialized slot (#13387). */
final class FloatConcatBuiltinTest extends TestCase
{
    public function testThreeOperandConcatWithFloatBuiltins(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/repro/maintainer_gap_float_concat_builtin.php';
        $cmd = 'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($path).' 2>/dev/null';
        $out = shell_exec($cmd);
        self::assertSame('-3 -2', $out);
    }

    public function testRoundAbsInConcatChain(): void
    {
        $root = dirname(__DIR__, 2);
        $script = <<<'PHP'
<?php
echo round(-2.5) . ' ' . abs(-3);
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc-concat-');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, $script);
        $out = shell_exec('php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($tmp).' 2>/dev/null');
        @unlink($tmp);
        self::assertSame('-3 3', $out);
    }
}
