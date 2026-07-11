<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableNestedExportLlvm;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Nested JIT HashTable/Variable method proxies for standalone helpers (#12910). */
final class NestedVmHashTableExportRuntimeTest extends TestCase
{
    public function testNestedScopeRegistersHashTableExportProxy(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_IMPORT);
        NestedJitCompileScope::run($ctx, static function () use ($ctx): void {
            HashTableNestedExportLlvm::ensureLinked($ctx);
            NestedVmVariableMethodLlvm::ensureMethod($ctx, 'resolveindirect');
            NestedVmVariableMethodLlvm::ensureMethod($ctx, 'null');
        });
        $this->assertTrue($ctx->functionIsRegistered(HashTableNestedExportLlvm::PROXY_NAME));
        $this->assertTrue($ctx->functionIsRegistered('phpcompiler\\vm\\variable::resolveindirect'));
        $this->assertTrue($ctx->functionIsRegistered('phpcompiler\\vm\\variable::null'));
    }
}
