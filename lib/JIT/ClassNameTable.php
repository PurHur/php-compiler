<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Runtime class-id → class-name lookup through one module-level table.
 *
 * Error paths that need the name of a runtime class (e.g. "Unsupported operand types:
 * Foo + int") used to inline one compare-and-raise arm per known class at every site —
 * several hundred arms per arithmetic op, which made the error path ~80% of a typical
 * function's IR and dominated compile time. Sites now call one internal function whose
 * body and table are emitted once, in {@see materialize()}, after every class is
 * registered (so the table also covers classes registered after the site was lowered).
 *
 * Unknown ids return the empty string; callers substitute their own fallback.
 */
final class ClassNameTable
{
    private const FN_NAME = '__phpc_class_name_by_id';

    /**
     * @return Value {@see __string__*}
     */
    public static function emitNameFromId(Context $context, Value $classId): Value
    {
        $fn = $context->module->getNamedFunction(self::FN_NAME);
        if (null === $fn) {
            $strPtr = $context->getTypeFromString('__string__*');
            $fn = $context->module->addFunction(
                self::FN_NAME,
                $context->context->functionType($strPtr, false, $context->getTypeFromString('int64'))
            );
            $context->llvm->lib->LLVMSetLinkage($fn->value, \llvm\llvm::LLVMInternalLinkage);
        }

        return $context->builder->call($fn, $context->builder->intCast($classId, $context->getTypeFromString('int64')));
    }

    /** Emit the table and lookup body. Idempotent; no-op when no site asked for a name. */
    public static function materialize(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::FN_NAME);
        if (!$fn instanceof Value\Function_ || $fn->countBasicBlocks() > 0) {
            return;
        }
        $names = [];
        $object = $context->type->object;
        if ($object instanceof Builtin\Type\Object_) {
            foreach ($object->allClassNamesById() as $id => $name) {
                if ((int) $id >= 0) {
                    $names[(int) $id] = (string) $name;
                }
            }
        }
        $count = [] === $names ? 0 : max(array_keys($names)) + 1;

        $lib = $context->llvm->lib;
        $strPtrPtr = $context->getTypeFromString('__string__**');
        $entries = [];
        for ($id = 0; $id < $count; ++$id) {
            $entries[] = $context->constantStringFromString($names[$id] ?? '')->value;
        }
        $tableTy = $strPtrPtr->arrayType(max(1, $count));
        if (0 === $count) {
            $entries[] = $context->constantStringFromString('')->value;
        }
        $table = $context->module->addGlobal($tableTy, self::FN_NAME.'_table');
        $table->setInitializer($context->llvm->factory->value(
            $context->context,
            $lib->LLVMConstArray(
                $strPtrPtr->type,
                $lib->makeArray(\llvm\LLVMValueRef_ptr::class, $entries),
                \count($entries)
            )
        ));
        $lib->LLVMSetGlobalConstant($table->value, 1);
        $lib->LLVMSetLinkage($table->value, \llvm\llvm::LLVMInternalLinkage);

        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        $i64 = $context->getTypeFromString('int64');
        $entry = $fn->appendBasicBlock('entry');
        $hit = $fn->appendBasicBlock('hit');
        $miss = $fn->appendBasicBlock('miss');
        $context->builder->positionAtEnd($entry);
        $id = $fn->getParam(0);
        $inRange = $context->builder->icmp(Builder::INT_ULT, $id, $i64->constInt($count, false));
        $context->builder->branchIf($inRange, $hit, $miss);
        $context->builder->positionAtEnd($hit);
        $slot = $context->builder->inBoundsGep($table, $i64->constInt(0, false), $id);
        $context->builder->returnValue($context->builder->load($context->builder->load($slot)));
        $context->builder->positionAtEnd($miss);
        $context->builder->returnValue($context->builder->load($context->constantStringFromString('')));
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * `$prefix . $name . $suffix` as a fresh refcount-1 string.
     *
     * @return Value {@see __string__*}
     */
    public static function emitConcat(Context $context, string $prefix, Value $name, string $suffix): Value
    {
        $b = $context->builder;
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $nameLen = $b->load($b->structGep($name, $map['length']));
        $total = $b->add($nameLen, $i64->constInt(\strlen($prefix) + \strlen($suffix), false));
        $out = $b->call($context->lookupFunction('__string__alloc'), $total);
        $dst = $b->pointerCast($b->structGep($out, $map['value']), $i8p);
        LibcExtern::ensureMemcpyDecl($context);
        $memcpy = $context->lookupFunction('memcpy');
        $offset = $i64->constInt(0, false);
        foreach ([$prefix, null, $suffix] as $part) {
            if (null === $part) {
                $src = $b->pointerCast($b->structGep($name, $map['value']), $i8p);
                $len = $nameLen;
            } elseif ('' === $part) {
                continue;
            } else {
                $lit = $b->load($context->constantStringFromString($part));
                $src = $b->pointerCast($b->structGep($lit, $map['value']), $i8p);
                $len = $i64->constInt(\strlen($part), false);
            }
            $b->call($memcpy, $b->gep($dst, $offset), $src, $len);
            $offset = $b->add($offset, $len);
        }

        return $out;
    }

    public const OTHER_OPERAND_MARKER = "\0phpc-other\0";

    /**
     * Raise the TypeError produced by `$messageForClass($className)` for the runtime class
     * `$classId` ("object" when the id has no name), through one raise sequence.
     * {@see OTHER_OPERAND_MARKER} in the message is replaced by `$otherName` (runtime).
     *
     * @param callable(string):string $messageForClass
     */
    public static function emitTypeErrorForClassId(
        Context $context,
        Value $classId,
        callable $messageForClass,
        ?Value $otherName = null
    ): void {
        $marker = "\0phpc-class\0";
        $b = $context->builder;
        $name = self::emitNameFromId($context, $classId);
        $nameLen = $b->load($b->structGep($name, $context->structFieldMap['__string__']['length']));
        $name = $b->select(
            $b->icmp(Builder::INT_EQ, $nameLen, $context->getTypeFromString('int64')->constInt(0, false)),
            $b->load($context->constantStringFromString('object')),
            $name
        );
        $parts = [];
        foreach (explode($marker, $messageForClass($marker)) as $i => $piece) {
            if ($i > 0) {
                $parts[] = $name;
            }
            foreach (explode(self::OTHER_OPERAND_MARKER, $piece) as $j => $sub) {
                if ($j > 0) {
                    $parts[] = $otherName ?? $b->load($context->constantStringFromString('mixed'));
                }
                $parts[] = $sub;
            }
        }
        ExceptionBridge::emitTypeErrorAndAbortWithStringValue($context, self::emitConcatParts($context, $parts));
    }

    /**
     * Concatenate literal and runtime {@see __string__*} parts into a fresh string.
     *
     * @param list<string|Value> $parts
     */
    public static function emitConcatParts(Context $context, array $parts): Value
    {
        $b = $context->builder;
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $total = $i64->constInt(0, false);
        $resolved = [];
        foreach ($parts as $part) {
            if (\is_string($part)) {
                if ('' === $part) {
                    continue;
                }
                $lit = $b->load($context->constantStringFromString($part));
                $resolved[] = [$lit, $i64->constInt(\strlen($part), false)];
            } else {
                $resolved[] = [$part, $b->load($b->structGep($part, $map['length']))];
            }
            $total = $b->add($total, end($resolved)[1]);
        }
        $out = $b->call($context->lookupFunction('__string__alloc'), $total);
        $dst = $b->pointerCast($b->structGep($out, $map['value']), $i8p);
        LibcExtern::ensureMemcpyDecl($context);
        $memcpy = $context->lookupFunction('memcpy');
        $offset = $i64->constInt(0, false);
        foreach ($resolved as [$str, $len]) {
            $b->call($memcpy, $b->gep($dst, $offset), $b->pointerCast($b->structGep($str, $map['value']), $i8p), $len);
            $offset = $b->add($offset, $len);
        }

        return $out;
    }

    /**
     * zend_zval_type_name() of a value box at runtime; objects give their class name.
     *
     * @return Value {@see __string__*}
     */
    public static function emitBoxTypeName(Context $context, Value $valuePtr): Value
    {
        $b = $context->builder;
        $i8 = $context->getTypeFromString('int8');
        $vmap = $context->structFieldMap['__value__'];
        $kind = $b->bitwiseAnd($b->load($b->structGep($valuePtr, $vmap['type'])), $i8->constInt(0x7f, false));
        $name = static fn (string $n): Value => $b->load($context->constantStringFromString($n));
        $result = $name('mixed');
        foreach ([
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_BOOL => 'bool',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_STRING & 0x7f => 'string',
            Variable::TYPE_HASHTABLE & 0x7f => 'array',
        ] as $tag => $label) {
            $result = $b->select($b->icmp(Builder::INT_EQ, $kind, $i8->constInt($tag, false)), $name($label), $result);
        }
        $isObject = $b->icmp(Builder::INT_EQ, $kind, $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false));
        $fn = BasicBlockHelper::parentFunction($context);
        $objBlock = $fn->appendBasicBlock('box_type_name_object');
        $join = $fn->appendBasicBlock('box_type_name_join');
        $from = $b->getInsertBlock();
        $b->branchIf($isObject, $objBlock, $join);
        $b->positionAtEnd($objBlock);
        $obj = $b->call($context->lookupFunction('__value__readObject'), $valuePtr);
        $classId = $b->load($b->structGep($obj, $context->structFieldMap['__object__']['class_id']));
        $className = self::emitNameFromId($context, $classId);
        $objEnd = $b->getInsertBlock();
        $b->branch($join);
        $b->positionAtEnd($join);
        $phi = $b->phi($result->typeOf());
        $phi->addIncoming($result, $from);
        $phi->addIncoming($className, $objEnd);

        return $phi;
    }
}
