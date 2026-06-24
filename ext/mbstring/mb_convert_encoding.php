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
 * mb_convert_encoding() — charset conversion via native CharsetEngine (#6251, pairs #3222).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_encoding)
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
        $source = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_convert_encoding',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $to = VmMbstring::coerceEncodingString($frame->calledArgs[1], 'mb_convert_encoding', 1);
        $from = 2 === $argc
            ? 'UTF-8'
            : VmMbstring::coerceEncodingString($frame->calledArgs[2], 'mb_convert_encoding', 2);
        if (!VmMbstring::isHtmlEntitiesEncoding($from) && null === CharsetEngine::parseEncodingSpec($from)) {
            throw new \ValueError(sprintf(
                'mb_convert_encoding(): Argument #3 ($from_encoding) is not a supported encoding, "%s" given',
                $from
            ));
        }
        if (!VmMbstring::isHtmlEntitiesEncoding($to) && null === CharsetEngine::parseEncodingSpec($to)) {
            throw new \ValueError(sprintf(
                'mb_convert_encoding(): Argument #2 ($to_encoding) is not a supported encoding, "%s" given',
                $to
            ));
        }
        $result = VmMbstring::convertEncoding($source, $to, $from);
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
            if (
                !VmMbstring::isHtmlEntitiesEncoding($fromLit)
                && null === CharsetEngine::parseEncodingSpec($fromLit)
            ) {
                return $context->getTypeFromString('bool')->constInt(0, false);
            }
            if (
                !VmMbstring::isHtmlEntitiesEncoding($toLit)
                && null === CharsetEngine::parseEncodingSpec($toLit)
            ) {
                return $context->getTypeFromString('bool')->constInt(0, false);
            }
            $converted = VmMbstring::convertEncoding($sourceLit, $toLit, $fromLit);
            if (false === $converted) {
                return $context->getTypeFromString('bool')->constInt(0, false);
            }

            return $context->constantFromString($converted);
        }

        throw new \LogicException('mb_convert_encoding() is not lowered for JIT/AOT in this compiler build');
    }
}
