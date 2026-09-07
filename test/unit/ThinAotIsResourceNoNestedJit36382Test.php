<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT is_resource / php://memory must not NestedJIT StreamLifecycle (#36382 / #23777).
 */
final class ThinAotIsResourceNoNestedJit36382Test extends TestCase
{
    public function testJitIsResourceUsesThinHandleTableOnStandalone(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/JitIsResource.php');
        $this->assertStringContainsString('isThinStandaloneAotMain', $src);
        $this->assertStringContainsString('StreamGlobalsJit::implementThinIsResource', $src);
        $this->assertStringContainsString('StreamBucket::ensureLinked', $src, 'embed path keeps StreamBucket');
        $this->assertMatchesRegularExpression(
            '/isThinStandaloneAotMain\(\).*implementThinIsResource/s',
            $src
        );
    }

    public function testThinLifecycleSkipsNestedJitHelperCompile(): void
    {
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamLifecycleKernel.php');
        $thin = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamLifecycleThinAot.php');
        $this->assertStringContainsString('JitStreamLifecycleThinAot::implement', $kernel);
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $thin);
        $this->assertStringContainsString('emitFcloseAndClearLlvmHandleSlot', $thin);
        $this->assertStringContainsString('implementLifecyclePeersForThinAot', $thin);
    }

    public function testThinFopenPathSkipsStreamFilterNestedJit(): void
    {
        $io = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
        $pos = strpos($io, 'function implementForUserScriptLowering');
        $this->assertNotFalse($pos);
        $body = substr($io, $pos, 2200);
        $this->assertStringContainsString('$userScriptImplementDepth', $io);
        $this->assertStringNotContainsString('StreamFilter::ensureLinked', $body);
        $this->assertStringContainsString('emitTryFopenDataUriStub', $body);
        $this->assertStringContainsString('implementPureLlvmApply', $body);
        $this->assertStringContainsString('#36382', $body);
    }

    public function testSpineIncludesThinLifecycle(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamLifecycleThinAot.php', $spine);
        $kernelPos = strpos($spine, 'JitStreamLifecycleKernel.php');
        $thinPos = strpos($spine, 'JitStreamLifecycleThinAot.php');
        $this->assertNotFalse($kernelPos);
        $this->assertNotFalse($thinPos);
    }
}
