<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmStreamContext;
use PHPUnit\Framework\TestCase;

/** VmStreamContext must not sync to host Zend stream resources (#6517/#6122 phase 2, #8058). */
final class VmStreamContextRuntimeShrinkTest extends TestCase
{
    public function testSetParamsDoesNotDelegateToHostStreamContextSetParams(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamContext.php');
        $this->assertStringNotContainsString("function_exists('stream_context_set_params')", $source);
        $this->assertStringNotContainsString('\\stream_context_set_params(', $source);
    }

    public function testSetOptionsDoesNotDelegateToHostStreamContextSetOptions(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamContext.php');
        $this->assertStringContainsString('VmParseStr::mergeInto', $source);
        $this->assertStringNotContainsString("function_exists('stream_context_set_options')", $source);
        $this->assertStringNotContainsString('\\stream_context_set_options(', $source);
        $this->assertStringNotContainsString('\\stream_context_set_option(', $source);
    }

    public function testStreamContextSetOptionsBuiltinUsesBuiltinExecute(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/stream_context_set_options.php');
        $this->assertStringContainsString('BuiltinExecute::writeReturn', $source);
        $this->assertStringNotContainsString('if (null === $frame->returnVar)', $source);
    }

    public function testSetOptionsMergesIntoVmHashTable(): void
    {
        $runtime = new Runtime();
        $ctx = VmStreamContext::create(['http' => ['timeout' => 5]]);
        $ctxVar = new \PHPCompiler\VM\Variable();
        $ctxVar->array($ctx);
        $options = new \PHPCompiler\VM\Variable();
        $options->newArray();
        $http = new \PHPCompiler\VM\Variable();
        $http->newArray();
        $follow = new \PHPCompiler\VM\Variable();
        $follow->int(0);
        $http->toArray()->add('follow_location', $follow);
        $options->toArray()->add('http', $http);

        $this->assertTrue(VmStreamContext::setOptions($ctxVar, $options));
        $table = $ctxVar->resolveIndirect()->toArray();
        $timeout = $table->find('http')->resolveIndirect()->toArray()->find('timeout');
        $this->assertSame(5, $timeout->resolveIndirect()->toInt());
        $followOut = $table->find('http')->resolveIndirect()->toArray()->find('follow_location');
        $this->assertSame(0, $followOut->resolveIndirect()->toInt());
    }

    public function testStreamContextSetParamsBuiltinUsesBuiltinExecute(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/stream_context_set_params.php');
        $this->assertStringContainsString('BuiltinExecute::writeReturn', $source);
        $this->assertStringNotContainsString('if (null === $frame->returnVar)', $source);
    }

    public function testSetParamsStoresParamsBagInVmHashTable(): void
    {
        $ctx = VmStreamContext::create(['http' => ['timeout' => 5]]);
        $ctxVar = new \PHPCompiler\VM\Variable();
        $ctxVar->array($ctx);
        $params = new \PHPCompiler\VM\Variable();
        $params->newArray();
        $source = new \PHPCompiler\VM\Variable();
        $source->string('unit-test');
        $params->toArray()->add('source', $source);

        $this->assertTrue(VmStreamContext::setParams($ctxVar, $params));
        $paramsSlot = $ctxVar->resolveIndirect()->toArray()->find(VmStreamContext::PARAMS_MARKER_KEY);
        $this->assertNotNull($paramsSlot);
        $sourceOut = $paramsSlot->resolveIndirect()->toArray()->find('source');
        $this->assertSame('unit-test', $sourceOut->resolveIndirect()->toString());
    }
}
