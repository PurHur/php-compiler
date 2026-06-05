<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringFilterEmail;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6082: AOT standalone must define __compiler_filter_validate_email (PHP LLVM, not C).
 *
 * @group aot-lint
 */
final class StringFilterEmailStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesFilterValidateEmailForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringFilterEmail::ensureLinked($ctx);
        $fn = $ctx->lookupFunction('__compiler_filter_validate_email');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
