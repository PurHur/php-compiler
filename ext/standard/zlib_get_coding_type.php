<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * zlib_get_coding_type() — active output compression handler (ext/zlib/zlib.c, issue #12280).
 *
 * php-src: ext/zlib/zlib.c — PHP_FUNCTION(zlib_get_coding_type)
 */
final class zlib_get_coding_type extends Internal
{
    public function __construct()
    {
        parent::__construct('zlib_get_coding_type');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError('zlib_get_coding_type() expects exactly 0 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $codingType = VmObGzhandler::getCodingType();
        if (false === $codingType) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($codingType);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'zlib_get_coding_type() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitZlib::getCodingType($context);
    }
}
