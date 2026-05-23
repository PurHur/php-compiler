<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\Runtime;

/**
 * @group aot-lint
 */
final class JitRuntimeClassConstTest extends TestCase
{
    public function testRuntimeModeAotClassConstIsJitSeedable(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        $classId = $ctx->type->object->lookup('PHPCompiler\\Runtime');
        $var = $ctx->type->object->classConstFetch($classId, 'MODE_AOT');
        $this->assertSame(Variable::TYPE_NATIVE_LONG, $var->type);
    }
}
