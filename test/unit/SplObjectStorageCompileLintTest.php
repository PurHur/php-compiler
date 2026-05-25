<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT lint for SplObjectStorage JIT lowering (#1998).
 *
 * @group aot-lint
 */
final class SplObjectStorageCompileLintTest extends TestCase
{
    public static function lintTargets(): iterable
    {
        yield 'spl_object_storage_dim' => ['test/bootstrap-aot/spl_object_storage_dim.php'];
        yield 'block_getframe_args_contains' => ['test/bootstrap-aot/block_getframe_args_contains.php'];
        yield 'spl_object_storage_jit_fixture' => ['test/compliance/fixtures/spl_object_storage_jit.phpt'];
    }

    /**
     * @dataProvider lintTargets
     */
    public function testCompileLintExitZero(string $rel): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/'.$rel;
        $this->assertFileExists($target);

        if (str_ends_with($rel, '.phpt')) {
            $sections = [];
            $section = '';
            foreach (file($target) as $line) {
                if (preg_match('/^--([A-Z]+)--/', $line, $m)) {
                    $section = $m[1];
                    $sections[$section] = '';
                    continue;
                }
                if ('' !== $section) {
                    $sections[$section] .= $line;
                }
            }
            $this->assertArrayHasKey('FILE', $sections);
            $tmp = tempnam(sys_get_temp_dir(), 'spl_storage_lint_');
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, $sections['FILE']);
            $target = $tmp;
        }

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg((string) $bin)
            .' -l '.escapeshellarg($target).' 2>&1';
        exec($cmd, $lines, $exit);
        if (isset($tmp)) {
            unlink($tmp);
        }
        $this->assertSame(
            0,
            $exit,
            implode("\n", $lines)."\n".'compile.php -l failed for '.$rel
        );
    }
}
