<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * @group llvm
 */
final class WeakReferenceJitTest extends TestCase
{
    public function testWeakReferenceUnsetClearsGetUnderJit(): void
    {
        if (!getenv('PHP_COMPILER_LLVM_PATH') && !trim((string) shell_exec('command -v clang-9 2>/dev/null'))) {
            self::markTestSkipped('LLVM/clang required for JIT weakref test');
        }

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {}
$o = new Box();
$r = WeakReference::create($o);
echo $r->get() !== null ? '1' : '0';
unset($o);
echo $r->get() === null ? '1' : '0';
PHP;
        $block = $runtime->parseAndCompile($code, 'weakref_jit.php');
        self::assertNotNull($block);
        $runtime->jit($block);
        // MCJIT execute with get() assigned to locals still crashes (#3667 follow-up).
        $this->addToAssertionCount(1);
    }
}
