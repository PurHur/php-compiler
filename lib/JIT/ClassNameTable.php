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

    /**
     * Raise the TypeError produced by `$messageForClass($className)` for the runtime class
     * `$classId` ("object" when the id has no name), through one raise sequence.
     *
     * @param callable(string):string $messageForClass
     */
    public static function emitTypeErrorForClassId(Context $context, Value $classId, callable $messageForClass): void
    {
        $marker = "\0phpc-class\0";
        [$prefix, $suffix] = explode($marker, $messageForClass($marker), 2) + [1 => ''];
        $b = $context->builder;
        $name = self::emitNameFromId($context, $classId);
        $nameLen = $b->load($b->structGep($name, $context->structFieldMap['__string__']['length']));
        $name = $b->select(
            $b->icmp(Builder::INT_EQ, $nameLen, $context->getTypeFromString('int64')->constInt(0, false)),
            $b->load($context->constantStringFromString('object')),
            $name
        );
        ExceptionBridge::emitTypeErrorAndAbortWithStringValue(
            $context,
            self::emitConcat($context, $prefix, $name, $suffix)
        );
    }
}
