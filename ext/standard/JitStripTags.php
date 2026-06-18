<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringStripTags;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT helpers for strip_tags() — runtime via StripTagsJitHelper (#9196); array allowed-tags markup here.
 */
final class JitStripTags
{
    private static int $seq = 0;

    public static function stripTags(Context $context, JITVariable $input, ?JITVariable $allowed = null): Value
    {
        $inputLiteral = $input->compileTimeString ?? null;
        if (null !== $inputLiteral) {
            $allowedLiteral = self::compileTimeAllowedValue($allowed);
            if (null !== $allowedLiteral || null === $allowed) {
                return $context->builder->load(
                    $context->constantStringFromString(VmString::stripTags($inputLiteral, $allowedLiteral))
                );
            }
        }

        $inPtr = JitStringArg::lower($context, $input, 'strip_tags() string');
        $allowPtr = self::jitAllowedArg($context, $allowed);
        StringStripTags::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_strip_tags'),
            $inPtr,
            $allowPtr
        );
    }

    /**
     * Build {@code <a><b>} allowed markup from a packed hashtable of tag names (#5053).
     */
    public static function allowedMarkupFromHashTable(Context $context, Value $haystack): Value
    {
        $tag = 'sta'.(string) ++self::$seq;
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $haystack
        );
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $zeroSize = $sizeT->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);
        $ltOpen = $context->builder->load($context->constantStringFromString('<'));
        $gtClose = $context->builder->load($context->constantStringFromString('>'));

        $mergeBlock = BasicBlockHelper::append($context, 'sta_merge_'.$tag);
        $emptyBlock = BasicBlockHelper::append($context, 'sta_empty_'.$tag);
        $workBlock = BasicBlockHelper::append($context, 'sta_work_'.$tag);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zeroSize);
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->load($context->constantStringFromString(''));
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($workBlock);
        $resultSlot = $context->builder->alloca($strPtr, 1, 'sta_acc_'.$tag);
        $context->builder->store(
            $context->builder->load($context->constantStringFromString('')),
            $resultSlot
        );

        $iSlot = $context->builder->alloca($sizeT, 1, 'sta_i_'.$tag);
        $context->builder->store($zeroSize, $iSlot);

        $loopHead = BasicBlockHelper::append($context, 'sta_head_'.$tag);
        $loopBody = BasicBlockHelper::append($context, 'sta_body_'.$tag);
        $loopDone = BasicBlockHelper::append($context, 'sta_done_'.$tag);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $num);
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $name = HashTableHelper::readStringAt($context, $haystack, $i);
        $wrapped = JitStringConcat::concat(
            $context,
            $ltOpen,
            JitStringConcat::concat($context, $name, $gtClose)
        );
        $acc = $context->builder->load($resultSlot);
        $context->builder->store(JitStringConcat::concat($context, $acc, $wrapped), $resultSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($i, $oneSize),
            $iSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $workResult = $context->builder->load($resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($strPtr, 'sta_result_'.$tag);
        $phi->addIncoming($emptyStr, $emptyBlock);
        $phi->addIncoming($workResult, $loopDone);

        return $phi;
    }

    /**
     * @return string|list<string>|null
     */
    private static function compileTimeAllowedValue(?JITVariable $allowed): string|array|null
    {
        if (null === $allowed) {
            return null;
        }
        if (JITVariable::TYPE_STRING === $allowed->type) {
            return $allowed->compileTimeString;
        }
        if (JITVariable::TYPE_VALUE === $allowed->type) {
            return null;
        }
        if (0 === ($allowed->type & JITVariable::IS_NATIVE_ARRAY)) {
            return null;
        }
        $compileTime = $allowed->compileTimeArray ?? null;
        if (!\is_array($compileTime)) {
            return null;
        }
        $names = [];
        foreach ($compileTime as $value) {
            if (!\is_string($value) && !\is_int($value) && !\is_float($value) && !\is_bool($value)) {
                return null;
            }
            $names[] = (string) $value;
        }

        return $names;
    }

    private static function jitAllowedArg(Context $context, ?JITVariable $allowed): Value
    {
        if (null === $allowed) {
            return $context->builder->load($context->constantStringFromString(''));
        }
        if (JITVariable::TYPE_STRING === $allowed->type) {
            try {
                return JitStringArg::lower($context, $allowed, 'strip_tags() allowed_tags');
            } catch (\LogicException) {
            }
        }
        $allowedValue = self::compileTimeAllowedValue($allowed);
        if (\is_array($allowedValue)) {
            return $context->builder->load(
                $context->constantStringFromString(VmString::formatAllowedTagsMarkup($allowedValue))
            );
        }

        throw new \LogicException('strip_tags() allowed_tags must be a string, array, or null in this compiler build');
    }
}
