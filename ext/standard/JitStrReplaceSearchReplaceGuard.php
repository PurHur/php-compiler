<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;

/**
 * php-src string.c php_str_replace_common — array $replace only when $search is array (#22827).
 */
final class JitStrReplaceSearchReplaceGuard
{
    private const TYPE_ERROR = '%s(): Argument #2 ($replace) must be of type string when argument #1 ($search) is a string';

    /**
     * @return bool true when the current block is terminated (caller must return a dummy value)
     */
    public static function rejectStringSearchWithArrayReplace(
        Context $context,
        JITVariable $search,
        JITVariable $replace,
        string $function
    ): bool {
        $message = \sprintf(self::TYPE_ERROR, $function);

        if (self::isKnownArray($search)) {
            return false;
        }

        if (self::isKnownArray($replace)) {
            // Compile-time: non-array $search + array $replace (incl. valueBoxHashtable literals).
            ExceptionBridge::emitTypeErrorAndAbort($context, $message);
            // Keep a reachable open insert block for assignCallResultOperand (#22827).
            BasicBlockHelper::ensureOpenInsertBlock($context, 'str_replace_known_arr_replace_cont');

            return true;
        }

        if (JITVariable::TYPE_VALUE !== $replace->type) {
            return false;
        }

        // Runtime boxed $replace — reject when type tag is array and $search is not.
        if (JITVariable::TYPE_STRING === $search->type
            || JITVariable::TYPE_NULL === $search->type
            || ($search->isNullConstant ?? false)
        ) {
            self::emitRejectIfBoxedArray($context, $replace, $message);

            return false;
        }

        if (JITVariable::TYPE_VALUE === $search->type) {
            self::emitRejectIfSearchNotArrayAndReplaceArray($context, $search, $replace, $message);
        }

        return false;
    }

    private static function isKnownArray(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return true;
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return true;
        }
        // Array init temps are often TYPE_VALUE with this flag (#22827 AOT).
        if ($arg->valueBoxHashtable ?? false) {
            return true;
        }

        return false;
    }

    private static function emitRejectIfBoxedArray(
        Context $context,
        JITVariable $replace,
        string $message
    ): void {
        $typeKind = self::boxedTypeKind($context, $replace);
        $i8 = $context->getTypeFromString('int8');
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(VmVariable::TYPE_ARRAY & 0x7f, false)
        );
        $errBlock = BasicBlockHelper::append($context, 'str_replace_search_str_replace_arr');
        $okBlock = BasicBlockHelper::append($context, 'str_replace_search_str_replace_ok');
        $context->builder->branchIf($isArray, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
        $context->builder->positionAtEnd($okBlock);
    }

    private static function emitRejectIfSearchNotArrayAndReplaceArray(
        Context $context,
        JITVariable $search,
        JITVariable $replace,
        string $message
    ): void {
        $searchKind = self::boxedTypeKind($context, $search);
        $replaceKind = self::boxedTypeKind($context, $replace);
        $i8 = $context->getTypeFromString('int8');
        $arrayTy = $i8->constInt(VmVariable::TYPE_ARRAY & 0x7f, false);
        $searchIsArray = $context->builder->icmp(Builder::INT_EQ, $searchKind, $arrayTy);
        $replaceIsArray = $context->builder->icmp(Builder::INT_EQ, $replaceKind, $arrayTy);
        $notSearchArray = $context->builder->not($searchIsArray);
        $bad = $context->builder->and($notSearchArray, $replaceIsArray);
        $errBlock = BasicBlockHelper::append($context, 'str_replace_boxed_search_replace_arr');
        $okBlock = BasicBlockHelper::append($context, 'str_replace_boxed_search_replace_ok');
        $context->builder->branchIf($bad, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
        $context->builder->positionAtEnd($okBlock);
    }

    private static function boxedTypeKind(Context $context, JITVariable $arg): \PHPLLVM\Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->and($typeByte, $i8->constInt(0x7f, false));
    }
}
