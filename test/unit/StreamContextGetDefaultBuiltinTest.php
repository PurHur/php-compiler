<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\stream_context_get_default;
use PHPCompiler\ext\standard\stream_context_get_options;
use PHPCompiler\ext\standard\stream_context_set_default;
use PHPCompiler\ext\standard\VmStreamContext;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** Issue #6367: stream_context_get_default / set_default VM builtins. */
final class StreamContextGetDefaultBuiltinTest extends TestCase
{
    public function testGetDefaultReturnsSharedSingleton(): void
    {
        $runtime = new Runtime();
        $get = new stream_context_get_default();
        $frame1 = $get->getFrame($runtime->vmContext);
        $frame1->calledArgs = [];
        $frame1->returnVar = new VMVariable();
        $get->execute($frame1);

        $frame2 = $get->getFrame($runtime->vmContext);
        $frame2->calledArgs = [];
        $frame2->returnVar = new VMVariable();
        $get->execute($frame2);

        $id1 = VmStreamContext::idFrom($frame1->returnVar);
        $id2 = VmStreamContext::idFrom($frame2->returnVar);
        $this->assertNotNull($id1);
        $this->assertSame($id1, $id2);
        $this->assertTrue($frame1->returnVar->resolveIndirect()->toArray()->isResourceLikeHandle());
    }

    public function testSetDefaultMergesOptionsIntoSingleton(): void
    {
        $runtime = new Runtime();
        $set = new stream_context_set_default();
        $options = new VMVariable();
        $options->newArray();
        $http = new VMVariable();
        $http->newArray();
        $timeout = new VMVariable();
        $timeout->int(6);
        $http->toArray()->add('timeout', $timeout);
        $options->toArray()->add('http', $http);

        $setFrame = $set->getFrame($runtime->vmContext);
        $setFrame->calledArgs = [$options];
        $setFrame->returnVar = new VMVariable();
        $set->execute($setFrame);

        $get = new stream_context_get_default();
        $getFrame = $get->getFrame($runtime->vmContext);
        $getFrame->calledArgs = [];
        $getFrame->returnVar = new VMVariable();
        $get->execute($getFrame);

        $getOpts = new stream_context_get_options();
        $optsFrame = $getOpts->getFrame($runtime->vmContext);
        $optsFrame->calledArgs = [$getFrame->returnVar];
        $optsFrame->returnVar = new VMVariable();
        $getOpts->execute($optsFrame);

        $httpOut = $optsFrame->returnVar->resolveIndirect()->toArray()->find('http')->resolveIndirect()->toArray();
        $this->assertSame(6, $httpOut->find('timeout')->resolveIndirect()->toInt());
    }

    public function testSetOptionsOnGetDefaultResultUpdatesSingleton(): void
    {
        $runtime = new Runtime();
        $get = new stream_context_get_default();
        $getFrame = $get->getFrame($runtime->vmContext);
        $getFrame->calledArgs = [];
        $getFrame->returnVar = new VMVariable();
        $get->execute($getFrame);

        $patch = new VMVariable();
        $patch->newArray();
        $httpPatch = new VMVariable();
        $httpPatch->newArray();
        $timeout = new VMVariable();
        $timeout->int(9);
        $httpPatch->toArray()->add('timeout', $timeout);
        $patch->toArray()->add('http', $httpPatch);

        $set = new \PHPCompiler\ext\standard\stream_context_set_options();
        $setFrame = $set->getFrame($runtime->vmContext);
        $setFrame->calledArgs = [$getFrame->returnVar, $patch];
        $setFrame->returnVar = new VMVariable();
        $set->execute($setFrame);

        $getAgain = new stream_context_get_default();
        $againFrame = $getAgain->getFrame($runtime->vmContext);
        $againFrame->calledArgs = [];
        $againFrame->returnVar = new VMVariable();
        $getAgain->execute($againFrame);

        $getOpts = new stream_context_get_options();
        $optsFrame = $getOpts->getFrame($runtime->vmContext);
        $optsFrame->calledArgs = [$againFrame->returnVar];
        $optsFrame->returnVar = new VMVariable();
        $getOpts->execute($optsFrame);

        $httpOut = $optsFrame->returnVar->resolveIndirect()->toArray()->find('http')->resolveIndirect()->toArray();
        $this->assertSame(9, $httpOut->find('timeout')->resolveIndirect()->toInt());
    }
}
