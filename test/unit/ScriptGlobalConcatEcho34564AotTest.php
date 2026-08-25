<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * @group aot
 * @group llvm
 */
final class ScriptGlobalConcatEcho34564AotTest extends TestCase
{
    public function testEncapsedEchoAfterScriptGlobalConcatAssign(): void
    {
        $src = dirname(__DIR__).'/repro/issue_34564_script_global_concat_echo_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34564_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileCode);
        $this->assertSame(0, $compileCode, implode("\n", $compileOut));
        $this->assertFileExists($bin);

        exec(escapeshellarg($bin).' 2>&1', $runOut, $runCode);
        @unlink($bin);
        $this->assertSame(0, $runCode, implode("\n", $runOut));
        $this->assertSame(["g=AB", "h=B"], $runOut);
    }

    public function testOpcodeSlotOrderMatchesExplicitConcat(): void
    {
        $src = dirname(__DIR__).'/repro/issue_34564_script_global_concat_echo_aot.php';
        $print = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/print.php')
            .' '.escapeshellarg($src).' 2>/dev/null';
        exec($print, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $text = implode("\n", $out);
        // Last ConcatList link dest must be a higher slot than its left ephemeral (#34564).
        $this->assertMatchesRegularExpression(
            '/TYPE_CONCAT\(\$(\d+), \$(\d+), LITERAL\(\'\n\'\)\).*TYPE_ECHO\(\$\1/s',
            $text
        );
        if (preg_match_all(
            '/TYPE_CONCAT\(\$(\d+), \$(\d+), LITERAL\(\'\n\'\)\)/',
            $text,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $row) {
                $this->assertGreaterThan(
                    (int) $row[2],
                    (int) $row[1],
                    'ConcatList result slot must outrank intermediate left: '.$row[0]
                );
            }
        } else {
            $this->fail('expected newline ConcatList links in opcodes');
        }
    }
}
