<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * strxfrm() — locale-aware string transformation (libc strxfrm; issue #4376).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(strxfrm)
 */
final class strxfrm extends Internal
{
    public function __construct()
    {
        parent::__construct('strxfrm');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('strxfrm() requires exactly one argument');
        }
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'strxfrm', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmLocaleCollate::strxfrm($string));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== \count($args)) {
            throw new \LogicException('strxfrm() requires exactly one argument');
        }

        if (null !== ($args[0]->compileTimeString ?? null)) {
            return $this->compileTimeString(
                $context,
                VmLocaleCollate::strxfrm($args[0]->compileTimeString)
            );
        }

        $srcStr = JitStringBuiltinArg::lower($context, $args[0], 'strxfrm', 0, 'string');
        $srcData = $this->stringDataPtr($context, $srcStr);

        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $nullSrc = $i8p->constNull();
        $zero = $sizeT->constInt(0, false);

        $needLen = $context->builder->call(
            $context->lookupFunction('strxfrm'),
            $nullSrc,
            $srcData,
            $zero
        );
        $needLenI64 = $context->builder->zExt($needLen, $i64);
        $isEmpty = $context->builder->icmp(Builder::INT_SLE, $needLenI64, $i64->constInt(0, false));

        $emptyBlock = BasicBlockHelper::append($context, 'strxfrm_empty');
        $workBlock = BasicBlockHelper::append($context, 'strxfrm_work');
        $doneBlock = BasicBlockHelper::append($context, 'strxfrm_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $this->compileTimeString($context, '');
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $one = $i64->constInt(1, false);
        $bufSize = $context->builder->truncOrBitCast(
            $context->builder->add($needLenI64, $one),
            $sizeT
        );
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $context->builder->call(
            $context->lookupFunction('strxfrm'),
            $bufChar,
            $srcData,
            $bufSize
        );
        $resultStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $needLenI64,
            $bufChar
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);
        $workEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtrTy);
        $phi->addIncoming($emptyStr, $emptyBlock);
        $phi->addIncoming($resultStr, $workEnd);

        return $phi;
    }

    private function compileTimeString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $charPtr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }
}
