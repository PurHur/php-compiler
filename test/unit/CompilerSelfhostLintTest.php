<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Bundled Compiler.php self-host AOT lint (issues #212, #78).
 *
 * JIT compile-time parameter defaults covered for the minimal bundle (#556):
 * null, int, float, bool, string, and array (including empty []).
 *
 * @group aot-lint
 */
final class CompilerSelfhostLintTest extends TestCase
{
    private const BUNDLE_ENTRY = 'test/selfhost/compiler_minimal/main.php';

    public function testBundledCompilerMinimalLintExitZero(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/'.self::BUNDLE_ENTRY;
        $this->assertFileExists($target);

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($bin)
            .' -l '.escapeshellarg($target).' 2>&1';
        exec($cmd, $lines, $exit);
        $this->assertSame(
            0,
            $exit,
            implode("\n", $lines)."\n".'compile.php -l failed for '.self::BUNDLE_ENTRY
        );
    }

    public function testLiteralIncludeDiscoveryFindsCompilerClosure(): void
    {
        $root = dirname(__DIR__, 2);
        $entry = $root.'/'.self::BUNDLE_ENTRY;
        $runtime = new Runtime(Runtime::MODE_AOT);
        $paths = Web\LiteralIncludeDiscovery::discoverAbsolutePaths($runtime, $entry);
        $rels = array_map(
            static fn (string $abs): string => substr($abs, strlen($root) + 1),
            $paths
        );
        sort($rels, SORT_STRING);
        $expected = [
            'lib/Block.php',
            'lib/Compiler.php',
            'lib/Frame.php',
            'lib/Func.php',
            'lib/Func/PHP.php',
            'lib/JIT/OperandName.php',
            'lib/Module.php',
            'lib/OpCode.php',
            'lib/OpCodeNames.php',
            'lib/Printer.php',
            'lib/Runtime.php',
            'lib/VM.php',
            'lib/VM/ClassProperty.php',
            'lib/VM/ScriptExit.php',
            'lib/Web/ConstStringFolder.php',
            'lib/Web/DeployRoot.php',
            'lib/Web/IncludePathResolver.php',
            'lib/Web/SourceBundler.php',
        ];
        $this->assertSame($expected, $rels);
    }
}
