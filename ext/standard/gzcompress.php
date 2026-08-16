<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * gzcompress() — zlib compress (ext/zlib/zlib.c parity, issue #3194).
 *
 * Soft-null $data on forward profile — Zend 8.4 deprecate+coerce (#21280; gzencode sibling: #21210).
 * Soft-null $level — E_DEPRECATED + coerce (#31445).
 */
final class gzcompress extends Internal
{
    public function __construct()
    {
        parent::__construct('gzcompress');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/zlib/zlib.c — ArgumentCountError (#30829).
        $this->requireArgCountRange($frame, 'gzcompress', 1, 3);
        $argc = \count($frame->calledArgs);
        // Soft-null — Zend 8.4 deprecate+coerce (#21280); leave shared VmZlibArg Z_PARAM_STR for siblings.
        $data = VmString::trimFamilyStringArgForFrame($frame, 0, 'gzcompress', 0, 'data');
        $level = -1;
        $encoding = \ZLIB_ENCODING_DEFLATE;
        // Named encoding without level — sparse calledArgs (#25012 sibling).
        if (isset($frame->calledArgs[1])) {
            $level = VmZlibArg::coerceLevel($frame, 1, 'gzcompress');
        }
        if (isset($frame->calledArgs[2])) {
            $encoding = VmZlibArg::coerceInt($frame, 2, 'gzcompress', 3, 'encoding');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZlib::gzcompress($data, $level, $encoding);
        if (false === $result) {
            VmZlib::triggerWarning($frame, 'gzcompress(): data error');

            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'gzcompress', 1, 3)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $argc = \count($args);
        $i64 = $context->getTypeFromString('int64');
        $level = $i64->constInt(-1, true);
        $encoding = $i64->constInt(\ZLIB_ENCODING_DEFLATE, false);
        if ($argc >= 2) {
            $level = JitStrictIntArg::lowerLevel($context, $args[1], 'gzcompress');
        }
        if (3 === $argc) {
            $encoding = JitStrictIntArg::lower($context, $args[2], 'gzcompress', 3, 'encoding');
        }

        return JitZlib::compress(
            $context,
            self::jitDataString($context, $args[0]),
            $level,
            $encoding
        );
    }

    private static function jitDataString(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'gzcompress',
                0,
                'data'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'gzcompress',
            0,
            'data'
        );
    }
}
