<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for stream_bucket_* (#6323, #7089). */
final class StreamBucket
{
    public static function ensureLinked(Context $context): void
    {
        StreamBucketRuntime::ensureLinked($context);
    }
}
