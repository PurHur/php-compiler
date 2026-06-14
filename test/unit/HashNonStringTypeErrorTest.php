<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** hash()/hash_hmac() non-string operand TypeError (#4951, ext/hash/hash.c). */
final class HashNonStringTypeErrorTest extends TestCase
{
    public function testVmNonStringOperandsMatchZend(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
foreach (['hash', 'hash_hmac'] as $fn) {
    try {
        $fn('md5', []);
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
try {
    hash([], 'data');
} catch (Throwable $e) {
    echo 'hash algo: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    hash_hmac('md5', 'data', new stdClass());
} catch (Throwable $e) {
    echo 'hash_hmac key: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    hash('md5', 'data', []);
} catch (Throwable $e) {
    echo 'hash binary: ', get_class($e), ': ', $e->getMessage(), "\n";
}
PHP,
            'hash_non_string_typeerror.php'
        );
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit) {
        }
        $this->assertSame(
            "hash: TypeError: hash(): Argument #2 (\$data) must be of type string, array given\n"
            . "hash_hmac: ArgumentCountError: hash_hmac() expects at least 3 arguments, 2 given\n"
            . "hash algo: TypeError: hash(): Argument #1 (\$algo) must be of type string, array given\n"
            . "hash_hmac key: TypeError: hash_hmac(): Argument #3 (\$key) must be of type string, stdClass given\n"
            . "hash binary: TypeError: hash(): Argument #3 (\$binary) must be of type bool, array given\n",
            ob_get_clean()
        );
    }

    /**
     * Issue #4951: AOT compile-only verify for hash()/hash_hmac TypeError lowering.
     *
     * @group llvm
     */
    public function testAotCompileOnlyTypeErrorLowering(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $target = dirname(__DIR__, 2).'/test/fixtures/aot/compile-only/hash_non_string_typeerror.php';
        $this->assertFileExists($target);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile((string) file_get_contents($target), 'hash_non_string_typeerror_jit_compile.php');
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }
}
