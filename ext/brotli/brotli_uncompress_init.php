<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\ext\standard\VmReflection;
use PHPLLVM\Value;

/** brotli_uncompress_init() — streaming decompress context (kjdev/php-ext-brotli; #27856). */
final class brotli_uncompress_init extends Internal
{
    public function __construct()
    {
        parent::__construct('brotli_uncompress_init');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'brotli_uncompress_init() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmBrotliContext::uncompressInit(VmReflection::requireContext($frame));
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, \PHPCompiler\JIT\Variable ...$args): Value
    {
        throw new \LogicException('brotli_uncompress_init() is VM-only in this compiler build (#27856)');
    }
}
