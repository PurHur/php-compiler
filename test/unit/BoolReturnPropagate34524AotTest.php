<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: `: bool` return with untyped operands must pass LLVM module verify (#34524).
 *
 * emitPropagateReturn used to emit `ret i64 0` into `i1` functions on TypeError
 * paths from untyped `%` / `==` — FilterIterator::accept(): bool failed the same way.
 *
 * @see php-src Zend/zend_execute.c return type path
 * @see php-src ext/spl/spl_iterators.c FilterIterator::accept
 *
 * @group llvm
 * @group aot
 */
final class BoolReturnPropagate34524AotTest extends TestCase
{
    private const EXPECTED = <<<'EOT'
bool(true)
bool(false)
24

EOT;

    public function testAotTypedBoolReturnAndFilterIteratorAcceptMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34524_bool_return_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34524_bool_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $out = shell_exec(escapeshellarg($bin).' 2>&1');
            $this->assertIsString($out);
            $this->assertSame(self::EXPECTED, $out);
        } finally {
            @unlink($bin);
        }
    }
}
