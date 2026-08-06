<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\FputcsvRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fputcsv() via CsvFputcsvJitHelper + __compiler_fwrite (#1193, #12447, #27180). */
final class JitFputcsv
{
    /** @return Value (int bytes written, or boolean false on failure) */
    public static function invoke(
        Context $context,
        Value $handleLong,
        Value $fieldsHt,
        Value $separatorStr,
        Value $enclosureStr,
        Value $escapeStr,
        Value $eolStr,
    ): Value {
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

        $defaultEnc = $context->builder->load($context->constantStringFromString('"'));
        $defaultEsc = $context->builder->load($context->constantStringFromString('\\'));
        $defaultEol = $context->builder->load($context->constantStringFromString("\n"));
        $encPhi = self::optionalCsvStringPhi($context, $enclosureStr, $defaultEnc, 'fputcsv_enc');
        $escPhi = self::optionalCsvStringPhi($context, $escapeStr, $defaultEsc, 'fputcsv_esc');
        $eolPhi = self::optionalCsvStringPhi($context, $eolStr, $defaultEol, 'fputcsv_eol');

        $line = FputcsvRuntime::formatFields($context, $fieldsHt, $gluePhi, $encPhi, $escPhi);
        $lineWithNl = JitStringConcat::concat($context, $line, $eolPhi);
        $i64 = $context->getTypeFromString('int64');

        return JitFwrite::invoke($context, $handleLong, $lineWithNl, JitFwrite::lengthWriteAll($context, $lineWithNl));
    }

    private static function optionalCsvStringPhi(
        Context $context,
        Value $arg,
        Value $default,
        string $tag
    ): Value {
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $isDefault = $context->builder->icmp(Builder::INT_EQ, $arg, $nullStr);
        $defaultBlock = BasicBlockHelper::append($context, $tag.'_default');
        $useBlock = BasicBlockHelper::append($context, $tag.'_use');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($isDefault, $defaultBlock, $useBlock);
        $context->builder->positionAtEnd($defaultBlock);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($useBlock);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($default, $defaultBlock);
        $phi->addIncoming($arg, $useBlock);

        return $phi;
    }
}
