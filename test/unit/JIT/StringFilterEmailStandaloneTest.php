<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringFilterEmail;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issues #5199 / #6082: filter_validate.c deleted; FILTER_VALIDATE_EMAIL via PHP LLVM.
 *
 * @group aot-lint
 */
final class StringFilterEmailStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesFilterValidateC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/filter_validate.c');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('filter_validate.c', $linker);
        $jitFilter = (string) file_get_contents(__DIR__.'/../../../ext/standard/JitFilter.php');
        $this->assertStringContainsString('StringFilterEmail', $jitFilter);
        $emailJit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringFilterEmail.php');
        $this->assertStringContainsString('__compiler_filter_validate_email', $emailJit);
    }

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
