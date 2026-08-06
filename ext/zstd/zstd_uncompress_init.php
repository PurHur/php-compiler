<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** zstd_uncompress_init() — streaming decompress context (kjdev/php-ext-zstd; #27882). */
final class zstd_uncompress_init extends Internal
{
    public function __construct()
    {
        parent::__construct('zstd_uncompress_init');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError('zstd_uncompress_init() expects at most 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZstdContext::uncompressInit(VmReflection::requireContext($frame));
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, \PHPCompiler\JIT\Variable ...$args): Value
    {
        throw new \LogicException('zstd_uncompress_init() is VM-only in this compiler build (#27882)');
    }
}
