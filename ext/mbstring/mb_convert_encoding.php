<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\iconv\CharsetEngine;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_convert_encoding() — charset conversion via native CharsetEngine (#6251, pairs #3222, #23562).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_encoding)
 * $from_encoding is array|string|null — null / omitted uses internal encoding; arrays /
 * comma lists use detect-then-convert (#31488).
 *
 * Excess argc → Zend `expects at most` ArgumentCountError (#30891).
 * JIT/AOT runtime strings via {@see JitMbConvertEncoding} NestedJIT (#34309).
 */
final class mb_convert_encoding extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_convert_encoding');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity 2..3 — excess uses at-most wording (#30891).
        $this->requireArgCountRange($frame, 'mb_convert_encoding', 2, 3);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $to = VmMbstring::coerceEncodingString($frame->calledArgs[1], 'mb_convert_encoding', 1);
        // Omitted or explicit null $from_encoding → mb_internal_encoding() (#31488).
        $fromList = 2 === $argc
            ? [MbstringState::internalEncoding()]
            : VmMbstring::coerceMbConvertFromEncodingList($frame->calledArgs[2]);
        if (!VmMbstring::isMbConvertPseudoEncoding($to) && null === CharsetEngine::parseEncodingSpec($to)) {
            throw new \ValueError(sprintf(
                'mb_convert_encoding(): Argument #2 ($to_encoding) must be a valid encoding, "%s" given',
                $to
            ));
        }
        $sourceVar = $frame->calledArgs[0]->resolveIndirect();
        // array|string $string — caller strict_types → TypeError on null; else soft-null (#29777 / #21282).
        if (Variable::TYPE_NULL === $sourceVar->type && InternalStrictArg::isCallerStrict($frame)) {
            throw new \TypeError(
                'mb_convert_encoding(): Argument #1 ($string) must be of type array|string, null given'
            );
        }
        if (Variable::TYPE_ARRAY === $sourceVar->type) {
            $result = VmMbstring::convertEncodingSourceArray(
                $sourceVar->toArray(),
                $to,
                $fromList,
                $frame
            );
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
                if (false === $result) {
                    $ret->bool(false);

                    return;
                }
                $ret->array($result);
            });

            return;
        }
        // Non-strict null is E_DEPRECATED + '' on 8.4 (php-src mbstring.c / #21282).
        $source = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_convert_encoding',
            0,
            'string',
            'array|string',
            false
        );
        $result = VmMbstring::convertEncodingWithFromList($source, $to, $fromList, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30891).
        if (!$this->requireArgCountRangeJit($context, $args, 'mb_convert_encoding', 2, 3)) {
            return self::foldFalse($context);
        }

        // Compile-time null $string — strict TypeError / weak soft-null (#29777 / #21282).
        $sourceIsNull = JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant;
        if ($sourceIsNull) {
            if (JitInternalStrictArg::rejectNullStringOrArray(
                $context,
                $args[0],
                'mb_convert_encoding',
                'string',
                1,
                false
            )) {
                return self::foldFalse($context);
            }
            JitStringBuiltinArg::emitNullStringParamDeprecation(
                $context,
                'mb_convert_encoding',
                0,
                'string',
                'array|string'
            );
        }

        return JitMbConvertEncoding::invoke($context, $args, $sourceIsNull);
    }

    private static function foldFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }
}
