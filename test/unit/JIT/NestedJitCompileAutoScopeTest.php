<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #10528: nested JIT::compile() auto-isolates blockStorage via compile depth.
 *
 * @group aot-lint
 */
final class NestedJitCompileAutoScopeTest extends TestCase
{
    public function testNestedCompileDoesNotLeakBlocksIntoOuterScope(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        $jit = new JIT($ctx);

        $ref = new \ReflectionClass(JIT::class);
        $depthProp = $ref->getProperty('compileDepth');
        $depthProp->setAccessible(true);
        $depthProp->setValue(null, 1);

        $outerCount = $ctx->scope->blockStorage->count();

        $block = $runtime->parseAndCompile(
            '<?php function nested_jit_auto_scope_probe(): int { return 1; }',
            'nested_jit_auto_scope_probe.php'
        );
        $this->assertNotNull($block);
        $jit->compile($block);

        $this->assertSame($outerCount, $ctx->scope->blockStorage->count());
        $this->assertSame(1, $depthProp->getValue());
    }
}
