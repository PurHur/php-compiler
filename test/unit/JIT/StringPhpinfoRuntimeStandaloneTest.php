<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringPhpinfoRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5304 phase 2 / #9256: phpinfo()/phpcredits() via PhpinfoJitHelper PHP, not LLVM HTML tables.
 *
 * @group aot-lint
 */
final class StringPhpinfoRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeDefinesPhpinfoHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringPhpinfoRuntime::ensureLinked($ctx);

        foreach (['__compiler_phpinfo', '__compiler_phpcredits'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }

    public function testStringPhpinfoRuntimeRoutesThroughPhpinfoJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringPhpinfoRuntime.php');
        $this->assertStringContainsString('PhpinfoJitHelper', $source);
        $this->assertStringNotContainsString('emitPhpinfoHtmlHeader', $source);
        $this->assertStringNotContainsString('emitObEchoCstr', $source);
    }

    public function testPhpinfoBuiltinNoLongerVmOnly(): void
    {
        $phpinfo = (string) file_get_contents(__DIR__.'/../../../ext/standard/phpinfo.php');
        $phpcredits = (string) file_get_contents(__DIR__.'/../../../ext/standard/phpcredits.php');
        $this->assertStringContainsString('JitInfo::phpinfo', $phpinfo);
        $this->assertStringContainsString('JitInfo::phpcredits', $phpcredits);
        $this->assertStringNotContainsString('is VM-only in this compiler build', $phpinfo);
        $this->assertStringNotContainsString('is VM-only in this compiler build', $phpcredits);
    }
}
