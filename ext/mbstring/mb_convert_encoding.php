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
 * $from_encoding is array|string|null — arrays / comma lists use detect-then-convert.
 */
final class mb_convert_encoding extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_convert_encoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_convert_encoding() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $to = VmMbstring::coerceEncodingString($frame->calledArgs[1], 'mb_convert_encoding', 1);
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
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('mb_convert_encoding() requires two or three arguments');
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

        $sourceLit = $sourceIsNull ? '' : JitStringBuiltinArg::compileTimeLiteral($args[0]);
        $toLit = JitStringBuiltinArg::compileTimeLiteral($args[1]);
        $fromLit = 2 === $argc ? 'UTF-8' : JitStringBuiltinArg::compileTimeLiteral($args[2]);
        if (null !== $sourceLit && null !== $toLit && null !== $fromLit) {
            $fromList = preg_split('/\s*,\s*/', $fromLit) ?: [];
            $fromList = array_values(array_filter($fromList, static fn (string $p): bool => '' !== $p));
            if ([] === $fromList) {
                return self::foldFalse($context);
            }
            foreach ($fromList as $from) {
                if (
                    !VmMbstring::isMbConvertPseudoEncoding($from)
                    && null === CharsetEngine::parseEncodingSpec($from)
                ) {
                    return self::foldFalse($context);
                }
            }
            if (
                !VmMbstring::isMbConvertPseudoEncoding($toLit)
                && null === CharsetEngine::parseEncodingSpec($toLit)
            ) {
                return self::foldFalse($context);
            }
            $converted = VmMbstring::convertEncodingWithFromList($sourceLit, $toLit, $fromList);
            if (false === $converted) {
                return self::foldFalse($context);
            }

            // constantFromString() is a C-string global — box as __string__/__value__ so AOT can
            // infer the array|string|false return (same shape as iconv fold; #28525).
            return self::foldString($context, $converted);
        }

        throw new \LogicException('mb_convert_encoding() is not lowered for JIT/AOT in this compiler build');
    }

    private static function foldFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }

    private static function foldString(Context $context, string $converted): Value
    {
        $strPtr = $context->builder->load($context->constantStringFromString($converted));
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $strPtr
        );

        return $ptr;
    }
}
