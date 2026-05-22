<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Bundled Compiler.php self-host AOT lint (issues #212, #78).
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

        $cmd = [PHP_BINARY, $bin, '-l', $target];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for '.self::BUNDLE_ENTRY
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
            'lib/Module.php',
            'lib/OpCode.php',
            'lib/Runtime.php',
            'lib/VM.php',
            'lib/Web/ConstStringFolder.php',
            'lib/Web/IncludePathResolver.php',
        ];
        $this->assertSame($expected, $rels);
    }
}
