<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Context::ensureFullStandaloneBodies string helpers: thin/init-phase gate, not StreamIo defer bag (#20571, peer #20553).
 */
final class ContextStandaloneBodiesRuntimeShrinkTest extends TestCase
{
    public function testFullStandaloneBodiesGatesStringHelpersOnThinOrInitPhase(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $fullPos = strpos($source, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $chunk = substr($source, $fullPos, 2500);
        $this->assertStringContainsString('isThinStandaloneAotMain', $chunk);
        $this->assertStringContainsString('isStandaloneInitPhase', $chunk);
        $this->assertStringContainsString('StringSoundex::ensureStandaloneBodies', $chunk);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $chunk);
    }

    public function testContextHasNoStreamIoDeferBagCallSites(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString(
            'StreamIoRuntime::shouldDeferHeavyStreamIoEmitters',
            $source
        );
    }
}
