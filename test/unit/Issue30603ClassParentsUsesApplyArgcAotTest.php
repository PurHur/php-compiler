<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: class_parents/class_uses/iterator_apply excess argc → ArgumentCountError (#30603).
 *
 * php-src: ext/standard/spl_functions.c / ext/spl/php_spl.c
 *
 * Separate binaries per helper family so ACE + NestedJIT link do not orphan the insert block.
 *
 * @group llvm
 * @group aot
 */
final class Issue30603ClassParentsUsesApplyArgcAotTest extends TestCase
{
    public function testAotClassParentsExcessArgcCatchableUnderTry(): void
    {
        $this->assertAotOutput(
            <<<'PHP'
<?php
try {
    class_parents(stdClass::class, true, 1);
    echo "parents_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'parents_hi ', $e->getMessage(), "\n";
}
try {
    class_parents();
    echo "parents_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'parents_lo ', $e->getMessage(), "\n";
}
echo "ok_parents_argc\n";
PHP,
            "parents_hi class_parents() expects at most 2 arguments, 3 given\n"
            ."parents_lo class_parents() expects at least 1 argument, 0 given\n"
            ."ok_parents_argc\n"
        );
    }

    public function testAotClassUsesExcessArgcCatchableUnderTry(): void
    {
        $this->assertAotOutput(
            <<<'PHP'
<?php
try {
    class_uses(stdClass::class, true, 1);
    echo "uses_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'uses_hi ', $e->getMessage(), "\n";
}
try {
    class_uses();
    echo "uses_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'uses_lo ', $e->getMessage(), "\n";
}
echo "ok_uses_argc\n";
PHP,
            "uses_hi class_uses() expects at most 2 arguments, 3 given\n"
            ."uses_lo class_uses() expects at least 1 argument, 0 given\n"
            ."ok_uses_argc\n"
        );
    }

    public function testAotIteratorApplyExcessArgcCatchableUnderTry(): void
    {
        $this->assertAotOutput(
            <<<'PHP'
<?php
try {
    iterator_apply(new ArrayIterator([]), fn () => 1, null, 1);
    echo "apply_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'apply_hi ', $e->getMessage(), "\n";
}
try {
    iterator_apply(new ArrayIterator([]));
    echo "apply_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'apply_lo ', $e->getMessage(), "\n";
}
echo "ok_apply_argc\n";
PHP,
            "apply_hi iterator_apply() expects at most 3 arguments, 4 given\n"
            ."apply_lo iterator_apply() expects at least 2 arguments, 1 given\n"
            ."ok_apply_argc\n"
        );
    }

    private function assertAotOutput(string $srcCode, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30603_try_'.getmypid().'_'.mt_rand().'.php';
        $bin = sys_get_temp_dir().'/phpc_30603_try_'.getmypid().'_'.mt_rand().'.bin';
        file_put_contents($src, $srcCode);
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.$i);
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
