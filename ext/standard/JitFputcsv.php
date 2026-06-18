<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fputcsv() via implode + __compiler_fwrite (issue #1193). */
final class JitFputcsv
{
    /** @return Value
     * (int bytes written, or boolean false on failure) */
    public static function invoke(
        Context $context,
        Value $handleLong,
        Value $fieldsHt,
        Value $separatorStr,
        Value $enclosureStr,
        Value $escapeStr,
    ): Value {
        unset($enclosureStr, $escapeStr);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $glue = $separatorStr;
        $isDefault = $context->builder->icmp(Builder::INT_EQ, $glue, $nullStr);
        $defaultBlock = BasicBlockHelper::append($context, 'fputcsv_glue_default');
        $useBlock = BasicBlockHelper::append($context, 'fputcsv_glue_use');
        $glueDone = BasicBlockHelper::append($context, 'fputcsv_glue_done');
        $context->builder->branchIf($isDefault, $defaultBlock, $useBlock);
        $context->builder->positionAtEnd($defaultBlock);
        $defaultGlue = $context->builder->load($context->constantStringFromString(','));
        $context->builder->branch($glueDone);
        $context->builder->positionAtEnd($useBlock);
        $context->builder->branch($glueDone);
        $context->builder->positionAtEnd($glueDone);
        $gluePhi = $context->builder->phi($strPtr);
        $gluePhi->addIncoming($defaultGlue, $defaultBlock);
        $gluePhi->addIncoming($glue, $useBlock);

        $line = JitImplode::implode($context, $gluePhi, $fieldsHt);
        $lineWithNl = JitStringConcat::concat(
            $context,
            $line,
            $context->builder->load($context->constantStringFromString("\n"))
        );
        $i64 = $context->getTypeFromString('int64');

        return JitFwrite::invoke($context, $handleLong, $lineWithNl, JitFwrite::lengthWriteAll($context, $lineWithNl));
    }
}
