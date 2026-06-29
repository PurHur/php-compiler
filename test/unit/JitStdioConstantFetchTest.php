<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable as VmVariable;
use PHPUnit\Framework\TestCase;

/**
 * VmStdStreamConstants registers STD* as VM objects; JIT must lower them to fd ints (#10163, #13301).
 */
final class JitStdioConstantFetchTest extends TestCase
{
    public function testVmContextRegistersStdioAsStreamObjects(): void
    {
        $runtime = new Runtime();
        foreach (['STDIN', 'STDOUT', 'STDERR'] as $name) {
            $var = $runtime->vmContext->constantFetch($name);
            $this->assertNotNull($var, $name);
            $this->assertSame(VmVariable::TYPE_OBJECT, $var->type, $name);
        }
    }

    public function testJitContextCoercesStdioObjectsBeforeLowering(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Context.php');
        $this->assertStringContainsString('vmStdioFdVariable', $source);
        $this->assertStringContainsString('TYPE_OBJECT === $phpVar->type', $source);
    }

    public function testPendingHeadersJitHelperCompilesForAot(): void
    {
        $runtime = new Runtime();
        $code = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/PendingHeadersJitHelper.php');
        $block = $runtime->parseAndCompile($code, 'PendingHeadersJitHelper.php');
        $this->assertNotNull($block, 'PendingHeadersJitHelper must compile for bootstrap spine (#13301)');
    }
}
