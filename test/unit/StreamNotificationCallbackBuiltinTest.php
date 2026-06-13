<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\stream_notification_callback;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** Issue #6055: stream_notification_callback() VM builtin. */
final class StreamNotificationCallbackBuiltinTest extends TestCase
{
    public function testNullCallbackReturnsPrevious(): void
    {
        $runtime = new Runtime();
        $builtin = new stream_notification_callback();
        $frame = $builtin->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();

        $nullArg = new VMVariable();
        $nullArg->null();
        $frame->calledArgs = [$nullArg];
        $builtin->execute($frame);
        $this->assertSame(VMVariable::TYPE_NULL, $frame->returnVar->resolveIndirect()->type);
    }

    public function testInvalidCallbackThrowsTypeError(): void
    {
        $runtime = new Runtime();
        $builtin = new stream_notification_callback();
        $frame = $builtin->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();

        $bad = new VMVariable();
        $bad->int(1);
        $frame->calledArgs = [$bad];

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage(
            'stream_notification_callback(): Argument #1 ($callback) must be a valid callback'
        );
        $builtin->execute($frame);
    }
}
