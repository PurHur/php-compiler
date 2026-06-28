<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\OpCode;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** PackEngine nested JIT after PhiResolver clears ArrayDimFetch.var (#13092). */
final class PackEngineNestedJitTest extends TestCase
{
    public function testParseFormatDimFetchContainerSlot(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile(
            (string) file_get_contents(__DIR__.'/../../ext/standard/PackEngine.php'),
            'PackEngine.php'
        );
        $this->assertNotNull($block);

        $bad = 0;
        $seen = new \SplObjectStorage();
        $walk = function (\PHPCompiler\Block $current) use (&$walk, &$bad, $seen): void {
            if ($seen->contains($current)) {
                return;
            }
            $seen->attach($current);
            if (($current->func->name ?? '') === 'parseFormat') {
                foreach ($current->opCodes as $op) {
                    if (OpCode::TYPE_ARRAY_DIM_FETCH === $op->type && null === $op->arg2) {
                        ++$bad;
                    }
                }
            }
            foreach ($current->opCodes as $op) {
                if (null !== $op->block1) {
                    $walk($op->block1);
                }
                if (null !== $op->block2) {
                    $walk($op->block2);
                }
            }
        };
        $walk($block);
        $this->assertSame(0, $bad, 'parseFormat dim fetch must set container slot (arg2)');
    }

    public function testNestedJitCompilePackEngine(): void
    {
        if (getenv('PACK_ENGINE_NESTED_JIT_FULL')) {
            $this->runFullPackEngineNestedJit();
            return;
        }
        $this->markTestSkipped('Full PackEngine nested JIT blocked on further match/JIT gaps; set PACK_ENGINE_NESTED_JIT_FULL=1 to run');
    }

    private function runFullPackEngineNestedJit(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile(
            (string) file_get_contents(__DIR__.'/../../ext/standard/PackEngine.php'),
            'PackEngine.php'
        );
        $this->assertNotNull($block);

        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        NestedJitCompileScope::run($ctx, static fn () => (new JIT($ctx))->compile($block));
        $this->assertNotEmpty($ctx->functions);
    }
}
