<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * msgfmt_format_message() — one-shot MessageFormat (php-src msgformat.c; #6366).
 *
 * Z_PARAM_STR $locale / $pattern — null TypeError under caller strict_types (#29921).
 * php-src msgformat.stub.php names the array argument $values (#24504).
 */
final class msgfmt_format_message extends Internal
{
    public function __construct()
    {
        parent::__construct('msgfmt_format_message');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'msgfmt_format_message() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $locale = VmMessageFormatter::coerceLocaleArgFromFrame(
            $frame,
            0,
            'msgfmt_format_message',
            0
        );
        $pattern = VmMessageFormatter::coercePatternArgFromFrame(
            $frame,
            1,
            'msgfmt_format_message',
            1
        );
        $args = VmMessageFormatter::coerceArgsArray($frame->calledArgs[2], 'msgfmt_format_message', 2);
        $result = VmMessageFormatter::formatMessage($locale, $pattern, $args);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'msgfmt_format_message() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        // Compile-time / typed null under strict_types — Z_PARAM_STR (#29921).
        foreach ([0 => 'locale', 1 => 'pattern'] as $idx => $param) {
            $nullConst = JITVariable::TYPE_NULL === $args[$idx]->type
                || ($args[$idx]->isNullConstant ?? false);
            if ($nullConst && $context->callerStrictTypes) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    \sprintf(
                        'msgfmt_format_message(): Argument #%d ($%s) must be of type string, null given',
                        $idx + 1,
                        $param
                    )
                );

                return self::boxNullResult($context);
            }
            JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[$idx],
                'msgfmt_format_message',
                $idx,
                $param
            );
        }

        throw new \Error('msgfmt_format_message() is not implemented for JIT in this compiler build (issue #6366)');
    }

    private static function boxNullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
