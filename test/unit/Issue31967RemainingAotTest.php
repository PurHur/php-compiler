<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Remaining AOT unimplemented-lowering rows from #31967.
 *
 * php-src: Zend/zend_execute.c ZEND_INIT_STATIC_METHOD_CALL;
 * Zend/zend_constants.c zend_get_class_constant_ex (interfaces);
 * Zend/zend_compile.c enum case in const expr;
 * Zend/zend_execute.c static property assign.
 *
 * @group llvm
 * @group aot
 */
final class Issue31967RemainingAotTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function reproProvider(): array
    {
        return [
            ['issue_31967_variable_static_call.php', 'U', '$obj::method()'],
            ['issue_31967_enum_class_const.php', 'h', 'enum case class const'],
            ['issue_31967_interface_self_const.php', '20', 'interface self:: const'],
            ['issue_31967_static_array_store.php', '1', 'static array store'],
        ];
    }

    /**
     * @dataProvider reproProvider
     */
    public function testAotReproMatchesZend(string $file, string $expected, string $label): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$file;
        $this->assertFileExists($src, $label);
        $bin = sys_get_temp_dir().'/phpc_issue_31967_'.getmypid().'_'.md5($file).'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, $label.' compile: '.implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, $label.' run: '.implode("\n", $runOut));
            $this->assertSame($expected, implode("\n", $runOut));
        } finally {
            @unlink($bin);
        }
    }
}
