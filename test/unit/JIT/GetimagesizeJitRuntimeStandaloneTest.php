<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\GetimagesizeJit;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #3271 / #27291: getimagesize AOT uses LLVM parse (no NestedJIT HashTable).
 *
 * @group aot-lint
 */
final class GetimagesizeJitRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneIsNoopAndParseLlvmExists(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        GetimagesizeJit::ensureStandaloneBodies($ctx);
        $this->assertTrue(\class_exists(\PHPCompiler\ext\standard\GetimagesizeParseLlvm::class));
        $this->assertFileExists(__DIR__.'/../../../ext/standard/GetimagesizeParseLlvm.php');
    }
}
