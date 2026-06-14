<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stream_bucket_prepend() — prepend StreamBucket to brigade (#6053, #7089). */
final class stream_bucket_prepend extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_bucket_prepend');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('stream_bucket_prepend() requires exactly 2 arguments');
        }
        $brigadeId = VmStreamBucket::requireBrigadeResource(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_bucket_prepend'
        );
        $bucketObj = VmStreamBucket::requireStreamBucketObject(
            $frame->calledArgs[1]->resolveIndirect(),
            'stream_bucket_prepend'
        );
        VmStreamBucket::prepend($brigadeId, $bucketObj);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStreamBucket::streamBucketPrepend($context, ...$args);
    }
}
