<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\stream_context_create;
use PHPCompiler\ext\standard\stream_context_get_options;
use PHPCompiler\ext\standard\stream_context_get_params;
use PHPCompiler\ext\standard\stream_context_set_option;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** Issue #3448: stream_context_set_option / get_params VM builtins. */
final class StreamContextSetOptionBuiltinTest extends TestCase
{
    public function testSingularSetOptionAndGetParams(): void
    {
        $runtime = new Runtime();
        $create = new stream_context_create();
        $createFrame = $create->getFrame($runtime->vmContext);
        $options = new VMVariable();
        $options->newArray();
        $http = new VMVariable();
        $http->newArray();
        $timeout = new VMVariable();
        $timeout->int(5);
        $http->toArray()->add('timeout', $timeout);
        $options->toArray()->add('http', $http);
        $createFrame->calledArgs = [$options];
        $createFrame->returnVar = new VMVariable();
        $create->execute($createFrame);

        $ctx = $createFrame->returnVar;
        $wrapper = new VMVariable();
        $wrapper->string('http');
        $option = new VMVariable();
        $option->string('user_agent');
        $value = new VMVariable();
        $value->string('phpc-test');

        $set = new stream_context_set_option();
        $setFrame = $set->getFrame($runtime->vmContext);
        $setFrame->calledArgs = [$ctx, $wrapper, $option, $value];
        $setFrame->returnVar = new VMVariable();
        $set->execute($setFrame);
        $this->assertTrue($setFrame->returnVar->resolveIndirect()->toBool());

        $get = new stream_context_get_options();
        $getFrame = $get->getFrame($runtime->vmContext);
        $getFrame->calledArgs = [$ctx];
        $getFrame->returnVar = new VMVariable();
        $get->execute($getFrame);
        $httpOut = $getFrame->returnVar->resolveIndirect()->toArray()->find('http')->resolveIndirect()->toArray();
        $this->assertSame('phpc-test', $httpOut->find('user_agent')->resolveIndirect()->toString());

        $params = new stream_context_get_params();
        $paramsFrame = $params->getFrame($runtime->vmContext);
        $paramsFrame->calledArgs = [$ctx];
        $paramsFrame->returnVar = new VMVariable();
        $params->execute($paramsFrame);
        $this->assertNotNull($paramsFrame->returnVar->resolveIndirect()->toArray()->find('options'));
    }
}
