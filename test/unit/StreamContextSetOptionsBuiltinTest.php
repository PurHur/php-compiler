<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\stream_context_create;
use PHPCompiler\ext\standard\VmStreamContext;
use PHPCompiler\ext\standard\stream_context_get_options;
use PHPCompiler\ext\standard\stream_context_set_options;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** Issue #6517: stream_context_set_options / get_options VM builtins. */
final class StreamContextSetOptionsBuiltinTest extends TestCase
{
    public function testSetAndGetOptionsMergeNestedHttpKeys(): void
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
        $patch = new VMVariable();
        $patch->newArray();
        $httpPatch = new VMVariable();
        $httpPatch->newArray();
        $follow = new VMVariable();
        $follow->int(0);
        $newTimeout = new VMVariable();
        $newTimeout->int(10);
        $httpPatch->toArray()->add('follow_location', $follow);
        $httpPatch->toArray()->add('timeout', $newTimeout);
        $patch->toArray()->add('http', $httpPatch);

        $set = new stream_context_set_options();
        $setFrame = $set->getFrame($runtime->vmContext);
        $setFrame->calledArgs = [$ctx, $patch];
        $setFrame->returnVar = new VMVariable();
        $set->execute($setFrame);
        $this->assertTrue($setFrame->returnVar->resolveIndirect()->toBool());

        $get = new stream_context_get_options();
        $getFrame = $get->getFrame($runtime->vmContext);
        $getFrame->calledArgs = [$ctx];
        $getFrame->returnVar = new VMVariable();
        $get->execute($getFrame);

        $opts = $getFrame->returnVar->resolveIndirect()->toArray();
        $httpOut = $opts->find('http')->resolveIndirect()->toArray();
        $this->assertSame(10, $httpOut->find('timeout')->resolveIndirect()->toInt());
        $this->assertSame(0, $httpOut->find('follow_location')->resolveIndirect()->toInt());
        $this->assertNull($opts->find(VmStreamContext::MARKER_KEY));
    }
}
