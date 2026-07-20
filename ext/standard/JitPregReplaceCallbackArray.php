<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\PregReplaceCallbackArrayRuntime;
use PHPCompiler\JIT\Builtin\StringPregMatch;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for preg_replace_callback_array() via PregReplaceCallbackArrayRuntime (#3568). */
final class JitPregReplaceCallbackArray
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context, JITVariable $patterns, JITVariable $subject): Value
    {
        StringPregMatch::ensureLinked($context);
        PregReplaceCallbackArrayRuntime::ensureLinked($context);

        // $subject soft-null DEP+coerce on 8.4 (#21318; php-src php_pcre.c).
        $subjectStr = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $subject, 'preg_replace_callback_array', 1, 'subject')
            : JitStringBuiltinArg::lower(
                $context,
                $subject,
                'preg_replace_callback_array',
                1,
                'subject',
                'array|string',
                null,
                false
            );

        $raw = $context->builder->call(
            $context->lookupFunction(PregReplaceCallbackArrayRuntime::ABI_REPLACE_CALLBACK_ARRAY),
            PregReplaceCallbackArrayRuntime::patternsToHashtable($context, $patterns),
            $subjectStr
        );

        $strPtrTy = $context->getTypeFromString('__string__*');
        $isError = $context->builder->icmp(Builder::INT_EQ, $raw, $strPtrTy->constNull());

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'preg_replace_callback_array_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'preg_replace_callback_array_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'preg_replace_callback_array_done_'.$id);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->branchIf($isError, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $raw);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
