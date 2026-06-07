<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\AttributeRegistryLowering;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6922: attribute lookup LLVM from PHP tables — no phpc_attr_* C runtime.
 *
 * @group aot-lint
 */
final class AttributeRegistryLoweringStandaloneTest extends TestCase
{
    public function testImplementLookupFunctionsDefinesCompilerAttrSymbols(): void
    {
        AttributeRegistryLowering::recordClass('box', ['AllowDynamicProperties']);
        AttributeRegistryLowering::recordMethod('box', 'ping', ['Deprecated']);

        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        AttributeRegistryLowering::implementLookupFunctions($ctx);

        foreach ([
            '__compiler_attr_class_count',
            '__compiler_attr_class_name_at',
            '__compiler_attr_method_count',
            '__compiler_attr_method_name_at',
            '__compiler_attr_class_args_hashtable',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }

        $this->assertNull($ctx->module->getNamedFunction('phpc_attr_class_count'));
    }
}
