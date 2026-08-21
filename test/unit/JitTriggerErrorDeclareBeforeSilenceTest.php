<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * JitTriggerErrorKernel must declare __compiler_trigger_error before SilenceRuntime NestedJIT (#33253).
 */
final class JitTriggerErrorDeclareBeforeSilenceTest extends TestCase
{
    public function testImplementDeclaresTriggerErrorAbiBeforeSilenceEnsureLinked(): void
    {
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTriggerErrorKernel.php');
        $this->assertStringContainsString('#33253', $kernel);
        $this->assertStringContainsString('declareTriggerErrorAbi', $kernel);
        $posDeclare = strpos($kernel, 'self::declareTriggerErrorAbi($context)');
        $posSilence = strpos($kernel, 'SilenceRuntime::ensureLinked($context)');
        $this->assertNotFalse($posDeclare);
        $this->assertNotFalse($posSilence);
        $this->assertLessThan(
            $posSilence,
            $posDeclare,
            'declareTriggerErrorAbi must run before SilenceRuntime::ensureLinked (#33253)'
        );
        $posBridge = strpos($kernel, 'self::implementTriggerErrorBridge($context)');
        $this->assertNotFalse($posBridge);
        $this->assertLessThan(
            $posBridge,
            $posSilence,
            'Silence NestedJIT runs before implementTriggerErrorBridge fills the body (#33253)'
        );
    }

    public function testNoNewRuntimeCForTriggerErrorDeclare(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/trigger_error_declare.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/trigger_error_declare.c');
    }
}
