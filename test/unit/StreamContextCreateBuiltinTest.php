<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\stream_context_create;
use PHPCompiler\ext\standard\VmStreamContext;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** Issue #1377: stream_context_create() VM builtin. */
final class StreamContextCreateBuiltinTest extends TestCase
{
    public function testCreatesArrayRepresentationWithOptions(): void
    {
        $runtime = new Runtime();
        $options = new VMVariable();
        $options->newArray();
        $http = new VMVariable();
        $http->newArray();
        $timeout = new VMVariable();
        $timeout->int(5);
        $httpTable = $http->toArray();
        $httpTable->add('timeout', $timeout);
        $optionsTable = $options->toArray();
        $optionsTable->add('http', $http);

        $builtin = new stream_context_create();
        $callFrame = $builtin->getFrame($runtime->vmContext);
        $callFrame->calledArgs = [$options];
        $callFrame->returnVar = new VMVariable();
        $builtin->execute($callFrame);

        $ctx = $callFrame->returnVar->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_ARRAY, $ctx->type);
        $this->assertTrue(VmStreamContext::isRepresentation($ctx));
        $table = $ctx->toArray();
        $httpOut = $table->find('http')->resolveIndirect()->toArray();
        $this->assertSame(5, $httpOut->find('timeout')->resolveIndirect()->toInt());
        $marker = $table->find(VmStreamContext::MARKER_KEY)->resolveIndirect();
        $this->assertGreaterThan(0, $marker->toInt());
    }

    public function testDefaultEmptyContext(): void
    {
        $runtime = new Runtime();
        $builtin = new stream_context_create();
        $callFrame = $builtin->getFrame($runtime->vmContext);
        $callFrame->calledArgs = [];
        $callFrame->returnVar = new VMVariable();
        $builtin->execute($callFrame);

        $ctx = $callFrame->returnVar->resolveIndirect();
        $this->assertTrue(VmStreamContext::isRepresentation($ctx));
        $marker = $ctx->toArray()->find(VmStreamContext::MARKER_KEY)->resolveIndirect();
        $this->assertGreaterThan(0, $marker->toInt());
    }

    public function testNestedOptionsDeepCopyDoesNotShareInputBuckets(): void
    {
        $runtime = new Runtime();
        $options = new VMVariable();
        $options->newArray();
        $socket = new VMVariable();
        $socket->newArray();
        $timeout = new VMVariable();
        $timeout->int(1);
        $socket->toArray()->add('connect_timeout', $timeout);
        $options->toArray()->add('socket', $socket);

        $builtin = new stream_context_create();
        $callFrame = $builtin->getFrame($runtime->vmContext);
        $callFrame->calledArgs = [$options];
        $callFrame->returnVar = new VMVariable();
        $builtin->execute($callFrame);

        $ctx = $callFrame->returnVar->resolveIndirect();
        $this->assertTrue(VmStreamContext::isRepresentation($ctx));
        $this->assertNotSame($options->toArray(), $ctx->toArray());
        $stored = new VMVariable();
        $stored->copyFrom($ctx);
        $this->assertSame(1, $stored->resolveIndirect()->toArray()->find('socket')->resolveIndirect()->toArray()->find('connect_timeout')->resolveIndirect()->toInt());
    }
}
