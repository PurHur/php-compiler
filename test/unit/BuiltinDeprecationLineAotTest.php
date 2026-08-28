<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: stdlib JIT E_DEPRECATED must cite the user call-site line, not line 0.
 *
 * @group llvm
 * @group aot
 */
final class BuiltinDeprecationLineAotTest extends TestCase
{
    public function testAotAbsNullDeprecationLineMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_abs_null_deprecated_silent.php';
        $bin = sys_get_temp_dir().'/phpc_builtin_dep_line_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));

        try {
            exec(escapeshellarg($bin).' 2>&1', $aotOut, $aotRc);
            $this->assertSame(0, $aotRc, implode("\n", $aotOut));
            $zendDep = $this->deprecationLines($zendOut);
            $aotDep = $this->deprecationLines($aotOut);
            $this->assertNotSame([], $zendDep, 'Zend must emit at least one deprecation');
            $this->assertNotSame([], $aotDep, 'AOT must emit at least one deprecation');
            $this->assertSame($zendDep[0], $aotDep[0]);
            $this->assertStringNotContainsString(' on line 0', $aotDep[0]);
        } finally {
            @unlink($bin);
        }
    }

    /** @param list<string> $lines */
    private function deprecationLines(array $lines): array
    {
        return array_values(array_filter(
            $lines,
            static fn (string $line): bool => str_contains($line, 'Deprecated:')
        ));
    }
}
