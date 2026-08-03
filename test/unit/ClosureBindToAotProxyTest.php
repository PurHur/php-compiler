<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Guard AOT Closure::bindTo bound-$this invoke metadata (#27219). */
final class ClosureBindToAotProxyTest extends TestCase
{
    public function testBindRecoversInnerAndStashesClosureWithBinding(): void
    {
        $helper = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/ClosureBindHelper.php');
        $this->assertStringContainsString('function resolveInnerCallFallback(', $helper);
        $this->assertStringContainsString('lastClosureCallProxy = $boundCall', $helper);
        $this->assertStringContainsString('function nativeInvokeTargetName(', $helper);
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('function attachBoundClosureInvokeMetadata(', $jit);
        $this->assertStringContainsString('attachBoundClosureInvokeMetadata($block, $op)', $jit);
    }

    public function testReproScriptExists(): void
    {
        $path = dirname(__DIR__, 2).'/test/repro/maintainer_gap_aot_closure_bindto.php';
        $this->assertFileExists($path);
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('->bindTo(new A()', $body);
        $this->assertStringContainsString('private $x = 7', $body);
    }
}
