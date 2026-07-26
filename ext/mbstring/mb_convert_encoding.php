<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\iconv\CharsetEngine;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
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
        if (!VmMbstring::isHtmlEntitiesEncoding($to) && null === CharsetEngine::parseEncodingSpec($to)) {
            throw new \ValueError(sprintf(
                'mb_convert_encoding(): Argument #2 ($to_encoding) must be a valid encoding, "%s" given',
                $to
            ));
        }
        $sourceVar = $frame->calledArgs[0]->resolveIndirect();
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
        // array|string $string — non-strict null is E_DEPRECATED + '' on 8.4 (php-src mbstring.c / #21282).
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

        $sourceLit = JitStringBuiltinArg::compileTimeLiteral($args[0]);
        $toLit = JitStringBuiltinArg::compileTimeLiteral($args[1]);
        $fromLit = 2 === $argc ? 'UTF-8' : JitStringBuiltinArg::compileTimeLiteral($args[2]);
        if (null !== $sourceLit && null !== $toLit && null !== $fromLit) {
            $fromList = preg_split('/\s*,\s*/', $fromLit) ?: [];
            $fromList = array_values(array_filter($fromList, static fn (string $p): bool => '' !== $p));
            if ([] === $fromList) {
                return $context->getTypeFromString('bool')->constInt(0, false);
            }
            foreach ($fromList as $from) {
                if (
                    !VmMbstring::isHtmlEntitiesEncoding($from)
                    && null === CharsetEngine::parseEncodingSpec($from)
                ) {
                    return $context->getTypeFromString('bool')->constInt(0, false);
                }
            }
            if (
                !VmMbstring::isHtmlEntitiesEncoding($toLit)
                && null === CharsetEngine::parseEncodingSpec($toLit)
            ) {
                return $context->getTypeFromString('bool')->constInt(0, false);
            }
            $converted = VmMbstring::convertEncodingWithFromList($sourceLit, $toLit, $fromList);
            if (false === $converted) {
                return $context->getTypeFromString('bool')->constInt(0, false);
            }

            return $context->constantFromString($converted);
        }

        throw new \LogicException('mb_convert_encoding() is not lowered for JIT/AOT in this compiler build');
    }
}
