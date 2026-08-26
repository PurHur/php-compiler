<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: foreach over IteratorAggregate whose getIterator() yields (#34980).
 *
 * @see php-src Zend/zend_interfaces.c zend_user_it_get_new_iterator
 * @see php-src Zend/zend_generators.c
 *
 * @group llvm
 * @group aot
 */
final class Issue34980IteratorAggregateGeneratorForeachAotTest extends TestCase
{
    public function testHydrateRejectsNonGeneratorClassHint(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/VM/GeneratorIteratorJitHelper.php'
        );
        $this->assertStringContainsString('#34980', $src);
        $this->assertStringContainsString("strcasecmp(ltrim(\$classHint, '\\\\'), 'Generator')", $src);
    }

    public function testAotIteratorAggregateGeneratorForeach(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/iterator_aggregate_generator_foreach_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34980_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_LLVM_ASSERT=1 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("k1", implode('', $runOut));
        } finally {
            @unlink($bin);
        }
    }
}
