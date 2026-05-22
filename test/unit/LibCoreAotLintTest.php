<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT lint gates for core lib units on the self-host path.
 */
final class LibCoreAotLintTest extends TestCase
{
    /**
     * @dataProvider lintTargetProvider
     */
    public function testLibFileAotLintPasses(string $rel): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -l '.escapeshellarg($root.'/'.$rel).' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public static function lintTargetProvider(): array
    {
        return [
            'Doctor' => ['lib/Doctor.php'],
            'JIT' => ['lib/JIT.php'],
            'Printer' => ['lib/Printer.php'],
            'Func' => ['lib/Func.php'],
            'Compiler' => ['lib/Compiler.php'],
            'Block' => ['lib/Block.php'],
            'Runtime' => ['lib/Runtime.php'],
            'VM' => ['lib/VM.php'],
            'Module' => ['lib/Module.php'],
            'OpCode' => ['lib/OpCode.php'],
            'Frame' => ['lib/Frame.php'],
        ];
    }
}
