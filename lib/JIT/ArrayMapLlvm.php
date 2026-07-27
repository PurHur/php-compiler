<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\VmInternalCall;
use PHPCompiler\Func\Internal;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM for array_map(null|compile-time-string-builtin) under thin standalone AOT (#23974).
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\ArrayMapJitHelper} still segfaults on
 * foreach + Variable property access / VmInternalCall (no active VM context). Peer of
 * {@see HashTableSliceLlvm}: keep the helper for VM/closures; lower these two shapes
 * without NestedJIT of the helper body.
 *
 * php-src: ext/standard/array.c — php_array_map()
 */
final class ArrayMapLlvm
{
    /** @var array<string, int> */
    private const MAP_CALLBACK_RESULT_TYPE = [
        // String keys avoid ::class const fetch during gen-0 bootstrap compile (#1492).
        'PHPCompiler\\ext\\standard\\strval' => Variable::TYPE_STRING,
        'PHPCompiler\\ext\\standard\\intval' => Variable::TYPE_NATIVE_LONG,
        'PHPCompiler\\ext\\standard\\floatval' => Variable::TYPE_NATIVE_DOUBLE,
        'PHPCompiler\\ext\\standard\\doubleval' => Variable::TYPE_NATIVE_DOUBLE,
        'PHPCompiler\\ext\\standard\\boolval' => Variable::TYPE_NATIVE_BOOL,
        'PHPCompiler\\ext\\standard\\strtolower' => Variable::TYPE_STRING,
        'PHPCompiler\\ext\\standard\\strtoupper' => Variable::TYPE_STRING,
        'PHPCompiler\\ext\\standard\\string_trim' => Variable::TYPE_STRING,
        'PHPCompiler\\ext\\standard\\string_ltrim' => Variable::TYPE_STRING,
        'PHPCompiler\\ext\\standard\\string_rtrim' => Variable::TYPE_STRING,
        'PHPCompiler\\ext\\types\\strlen' => Variable::TYPE_NATIVE_LONG,
    ];

    /** Identity copy preserving packed numeric keys (array_map(null, …)). */
    public static function mapNull(Context $context, Value $src): Value
    {
        return self::mapPacked($context, $src, null, Variable::TYPE_VALUE, 'array_map_null');
    }

    /** Map each packed element through a compile-time string builtin (strtoupper, strval, …). */
    public static function mapBuiltin(Context $context, Value $src, string $builtinName): Value
    {
        $handler = VmInternalCall::resolveStringCallback($builtinName);
        $resultType = self::mapCallbackResultType($handler);

        return self::mapPacked($context, $src, $handler, $resultType, 'array_map');
    }

    private static function mapCallbackResultType(Internal $handler): int
    {
        $type = self::MAP_CALLBACK_RESULT_TYPE[$handler::class] ?? null;
        if (null === $type) {
            throw new \LogicException(
                'array_map() callback is not supported by the JIT compiler in this build'
            );
        }

        return $type;
    }

    /**
     * @param Internal|null $handler null => identity copy into value boxes
     */
    private static function mapPacked(
        Context $context,
        Value $src,
        ?Internal $handler,
        int $resultType,
        string $prefix
    ): Value {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, $prefix.'_empty');
        $workBlock = BasicBlockHelper::append($context, $prefix.'_work');
        $doneBlock = BasicBlockHelper::append($context, $prefix.'_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, $prefix.'_src');
        $context->builder->store($zero, $srcIdxSlot);
        $head = BasicBlockHelper::append($context, $prefix.'_head');
        $check = BasicBlockHelper::append($context, $prefix.'_check');
        $mapBlock = BasicBlockHelper::append($context, $prefix.'_map');
        $skip = BasicBlockHelper::append($context, $prefix.'_skip');
        $advance = BasicBlockHelper::append($context, $prefix.'_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $srcIdx
        );
        $context->builder->branchIf($isSet, $mapBlock, $skip);

        $context->builder->positionAtEnd($mapBlock);
        $elem = HashTableHelper::readIndexedToValueBox($context, $src, $srcIdx);
        if (null === $handler) {
            HashTableHelper::setAtIndex($context, $dest, $srcIdx, $elem);
        } else {
            $mapped = $handler->call($context, $elem);
            self::storeMappedAtIndex(
                $context,
                $dest,
                $srcIdx,
                new Variable($context, $resultType, Variable::KIND_VALUE, $mapped),
                $resultType
            );
        }
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($srcIdx, $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);

        return $phi;
    }

    private static function storeMappedAtIndex(
        Context $context,
        Value $dest,
        Value $index,
        Variable $element,
        int $resultType
    ): void {
        switch ($resultType) {
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setLongAt'),
                    $dest,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_STRING:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringAt'),
                    $dest,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_NATIVE_BOOL:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setBoolAt'),
                    $dest,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setDoubleAt'),
                    $dest,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            default:
                throw new \LogicException(
                    'array_map() mapped value type not supported for JIT: '
                    .Variable::getStringType($resultType)
                );
        }
    }
}
