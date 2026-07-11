<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringFsGlob;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5459 / #12909: glob/scandir standalone via FsGlobJitHelper PHP (no StandaloneLlvm vec).
 *
 * @group aot-lint
 */
final class StringFsGlobVecStandaloneTest extends TestCase
{
    public function testEnsureLinkedCompilesFsGlobJitHelperForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringFsGlob::ensureLinked($ctx);

        $this->assertNotNull(
            $ctx->functions[\strtolower('PHPCompiler\\ext\\standard\\FsGlobJitHelper::globArgv')] ?? null,
            'FsGlobJitHelper::globArgv must compile in standalone via nested JIT (#12909)'
        );
        $this->assertNotNull(
            $ctx->functions[\strtolower('PHPCompiler\\ext\\standard\\FsGlobJitHelper::scandirArgv')] ?? null,
            'FsGlobJitHelper::scandirArgv must compile in standalone via nested JIT (#12909)'
        );
    }
}
