<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stream_bucket_new() — create stdClass bucket for filter brigades (#6053, #7089, #10325). */
final class stream_bucket_new extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_bucket_new');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('stream_bucket_new() requires exactly 2 arguments');
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }
        VmStreamArg::requireStreamHandle($frame->calledArgs[0]->resolveIndirect(), 'stream_bucket_new', 1);
        $buffer = VmStreamBucket::requireBufferString(
            $frame->calledArgs[1]->resolveIndirect(),
            'stream_bucket_new',
            2
        );
        $frame->returnVar->copyFrom(VmStreamBucket::newBucketObject($frame->vmContext, 0, $buffer));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStreamBucket::streamBucketNew($context, ...$args);
    }
}
