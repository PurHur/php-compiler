<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringQuotPrint;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5376: AOT standalone must define quoted_printable helpers without phpc_quot_print.c.
 *
 * @group aot-lint
 */
final class StringQuotPrintRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesQuotPrintForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringQuotPrint::ensureLinked($ctx);

        foreach (['__compiler_quoted_printable_encode', '__compiler_quoted_printable_decode'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
