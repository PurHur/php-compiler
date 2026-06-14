<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stream_bucket_make_writeable() — pop first bucket from brigade (#6053, #7089). */
final class stream_bucket_make_writeable extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_bucket_make_writeable');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('stream_bucket_make_writeable() requires exactly 1 argument');
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }
        $brigadeId = VmStreamBucket::requireBrigadeResource(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_bucket_make_writeable'
        );
        $bucket = VmStreamBucket::makeWriteable($frame->vmContext, $brigadeId);
        if (null === $bucket) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->copyFrom($bucket);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStreamBucket::streamBucketMakeWriteable($context, ...$args);
    }
}
