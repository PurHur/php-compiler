<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Operand\Literal;
use PHPCompiler\Block;
use PHPCompiler\ext\spl\SplDualIteratorStorage;
use PHPCompiler\Frame;
use PHPCompiler\OpCode;
use PHPCompiler\VM as VmEngine;

/**
 * Evaluate class constant initializer opcodes (e.g. {@code new stdClass()}) at class definition.
 *
 * @see Zend/zend_compile.c zend_compile_const_expr (php-src)
 */
final class ClassConstMaterializer
{
    /**
     * Register user classes from the compilation unit so JIT/AOT const materialization
     * can evaluate `new UserClass` (and bare `new UserClass` on 8.4+) before runtime (#19046).
     */
    public static function seedReferencedClasses(
        VmEngine $vm,
        ?Block $rootBlock,
        Block $classBody,
        int $valueSlot
    ): void {
        if (null === $rootBlock) {
            return;
        }
        $classBlocks = self::collectScriptClassBlocks($rootBlock);
        if ([] === $classBlocks) {
            return;
        }
        $requiredLc = [];
        foreach (self::expandRequiredNewClassNames($classBody, $valueSlot, $classBlocks) as $className) {
            $requiredLc[strtolower(ltrim($className, '\\'))] = $className;
        }
        if ([] === $requiredLc) {
            return;
        }
        foreach ($rootBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS !== $op->type || null === $op->block1) {
                continue;
            }
            $nameOp = $rootBlock->getOperand($op->arg1);
            if (!$nameOp instanceof Literal) {
                continue;
            }
            $lc = strtolower(ltrim($nameOp->value, '\\'));
            if (!isset($requiredLc[$lc])) {
                continue;
            }
            $vm->ensureClassDeclaredForConstMaterialization($nameOp->value, $op->block1);
            unset($requiredLc[$lc]);
        }
        foreach ($requiredLc as $className) {
            $body = $classBlocks[strtolower(ltrim($className, '\\'))] ?? null;
            if (null !== $body) {
                $vm->ensureClassDeclaredForConstMaterialization($className, $body);
            }
        }
    }

    public static function materializeSlot(
        VmEngine $vm,
        Block $bodyBlock,
        int $valueSlot,
        ?string $declaringClassName = null
    ): Variable {
        $initOps = [];
        foreach ($bodyBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS_CONST === $op->type && $valueSlot === $op->arg2) {
                break;
            }
            $initOps[] = $op;
        }
        $newResultSlot = self::newFragmentResultSlot($initOps);
        if (null !== $newResultSlot) {
            return self::detachConstantValue(
                $vm->materializeClassConstInitFragment(
                    $bodyBlock->fragmentForOpcodes($initOps),
                    $newResultSlot
                )
            );
        }

        $frame = $bodyBlock->getFrame($vm->context);
        $entry = self::declaringClassEntry($vm, $declaringClassName);
        foreach ($bodyBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS_CONST === $op->type && $valueSlot === $op->arg2) {
                break;
            }
            if ($vm->isClassBodyConstInitOpcode($op->type)) {
                $vm->executeClassBodyConstInitOpcode($frame, $op);
                continue;
            }
            if (null !== $entry && ClassConstExpr::isSupportedOpcode($op->type)) {
                ClassConstExpr::execute($vm->context, $frame, $bodyBlock, $op, $entry);
                continue;
            }
            if (null !== $entry && OpCode::TYPE_DECLARE_CLASS_CONST === $op->type) {
                self::registerPriorClassConst($vm->context, $bodyBlock, $frame, $entry, $op);
            }
        }
        if (!isset($frame->scope[$valueSlot])) {
            throw new \LogicException('Class constant value must be a compile-time constant');
        }

        return self::detachConstantValue($frame->scope[$valueSlot]);
    }

    /**
     * @param list<OpCode> $initOps
     */
    private static function newFragmentResultSlot(array $initOps): ?int
    {
        foreach ($initOps as $initOp) {
            if (OpCode::TYPE_NEW === $initOp->type) {
                return $initOp->arg1;
            }
        }

        return null;
    }

    private static function declaringClassEntry(VmEngine $vm, ?string $declaringClassName): ?ClassEntry
    {
        if (null === $declaringClassName || '' === $declaringClassName) {
            return null;
        }
        $name = ltrim($declaringClassName, '\\');
        $lc = strtolower($name);
        if (isset($vm->context->classes[$lc])) {
            return $vm->context->classes[$lc];
        }
        $entry = new ClassEntry($name);
        $vm->context->classes[$lc] = $entry;

        return $entry;
    }

    private static function registerPriorClassConst(
        Context $context,
        Block $bodyBlock,
        Frame $frame,
        ClassEntry $entry,
        OpCode $op
    ): void {
        $canonical = $frame->scope[$op->arg1]->toString();
        $name = \PHPCompiler\ClassConstName::key($canonical);
        if (isset($bodyBlock->constants[$op->arg2])) {
            $const = $bodyBlock->constants[$op->arg2];
            if (!$const->is(Variable::TYPE_NULL)) {
                $value = new Variable();
                $value->copyFrom($const);
                $entry->constants[$name] = EnumCaseSupport::materializeConstantValue($context, $value);
                $entry->constNames[$name] = $canonical;

                return;
            }
        }
        if (isset($frame->scope[$op->arg2])) {
            $entry->constants[$name] = EnumCaseSupport::materializeConstantValue(
                $context,
                $frame->scope[$op->arg2]
            );
            $entry->constNames[$name] = $canonical;
        }
    }

    /**
     * Store an immortal copy of a class constant value (shared identity on fetch).
     */
    public static function detachConstantValue(Variable $src): Variable
    {
        $src = $src->resolveIndirect();
        $stored = new Variable($src->type);
        switch ($src->type) {
            case Variable::TYPE_NULL:
                $stored->null();
                break;
            case Variable::TYPE_STRING:
                $str = $src->optionalScalarString();
                if (null === $str) {
                    $stored->undefined();
                    break;
                }
                // Preserve ZSTR_IS_INTERNED through global/const materialize (#22716).
                $stored->string($str, $src->stringInterned);
                break;
            case Variable::TYPE_INTEGER:
                if ($src->isStreamResource()) {
                    $stored->legacyStreamHandle($src->toInt());
                } elseif ($src->isDirResource()) {
                    $stored->legacyDirHandle($src->toInt());
                } else {
                    $int = $src->optionalScalarInt();
                    if (null === $int) {
                        $stored->undefined();
                        break;
                    }
                    $stored->int($int);
                }
                break;
            case Variable::TYPE_FLOAT:
                $stored->float($src->toFloat());
                break;
            case Variable::TYPE_BOOLEAN:
                $stored->bool($src->toBool());
                break;
            case Variable::TYPE_OBJECT:
                $srcObj = $src->toObject();
                if (null !== $srcObj->closureState) {
                    $stored->copyFrom($src);
                    break;
                }
                // Builtin instances keep object identity — SplDualIteratorStorage keys on ObjectEntry::id (#17721).
                if ($srcObj->class->isInternal) {
                    $stored->object($srcObj);
                    break;
                }
                // Enum case singletons — preserve magic name/value metadata (#17743, #17744, Zend/zend_enum.c).
                if (EnumCaseSupport::isEnumCase($srcObj)) {
                    $backing = new Variable();
                    $backing->null();
                    if (null !== $srcObj->enumCaseValue) {
                        $backing->copyFrom($srcObj->enumCaseValue);
                    }
                    $objVar = EnumCaseSupport::createCase(
                        $srcObj->class,
                        $srcObj->enumCaseName ?? '',
                        $backing
                    );
                    $stored->object($objVar->toObject());
                    break;
                }
                $detached = new ObjectEntry($srcObj->class);
                $detached->constructed = $srcObj->constructed;
                foreach ($srcObj->propertiesWithNames() as $propName => $propVar) {
                    if (!self::isDetachablePropertySlot($propVar)) {
                        continue;
                    }
                    $detached->allocateProperty($propName)->copyFrom(
                        self::detachConstantValue($propVar)
                    );
                }
                SplDualIteratorStorage::transferState($srcObj->id, $detached->id);
                $stored->object($detached);
                break;
            case Variable::TYPE_ENUM_CASE:
                $case = $src->toEnumCase();
                $objVar = EnumCaseSupport::createCase($case->enumClass, $case->caseName, $case->backingValue);
                $stored->object($objVar->toObject());
                break;
            case Variable::TYPE_ARRAY:
                $stored->array($src->toArray());
                break;
            case Variable::TYPE_PROPERTY_HOOK_REF:
                $stored->copyFrom($src);
                break;
            default:
                throw new \LogicException(
                    'Unsupported class constant value type: '.$src->type
                );
        }

        return $stored;
    }

    /** Skip uninitialized typed instance slots (ext/dom prototypes, #17722). */
    private static function isDetachablePropertySlot(Variable $propVar): bool
    {
        if (TypedPropertyCheck::isUninitialized($propVar)) {
            return false;
        }
        $resolved = $propVar->resolveIndirect();
        if (Variable::TYPE_STRING === $resolved->type && null === $resolved->optionalScalarString()) {
            return false;
        }
        if (
            Variable::TYPE_INTEGER === $resolved->type
            && null === $resolved->optionalScalarInt()
            && !$resolved->isStreamResource()
            && !$resolved->isDirResource()
        ) {
            return false;
        }

        return true;
    }

    /**
     * Compile-time (object) array cast for define()/const (#17676, basic_functions.c).
     */
    public static function materializeStdClassFromArrayVariable(Variable $src): ?Variable
    {
        $src = $src->resolveIndirect();
        if ($src->is(Variable::TYPE_OBJECT)) {
            return self::detachConstantValue($src);
        }
        if (!$src->is(Variable::TYPE_ARRAY)) {
            return null;
        }
        $classEntry = new ClassEntry('stdClass');
        $object = new ObjectEntry($classEntry);
        $object->constructed = true;
        foreach ($src->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $propName = $keyVar->is(Variable::TYPE_INTEGER)
                ? (string) $keyVar->toInt()
                : $keyVar->toString();
            $object->allocateProperty($propName)->copyFrom(self::detachConstantValue($valueVar));
        }
        $stored = new Variable(Variable::TYPE_OBJECT);
        $stored->object($object);

        return $stored;
    }

    /**
     * @return array<string, Block> lowercase unqualified class name => body block
     */
    private static function collectScriptClassBlocks(Block $rootBlock): array
    {
        $map = [];
        foreach ($rootBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS !== $op->type || null === $op->block1) {
                continue;
            }
            $nameOp = $rootBlock->getOperand($op->arg1);
            if (!$nameOp instanceof Literal) {
                continue;
            }
            $map[strtolower(ltrim($nameOp->value, '\\'))] = $op->block1;
        }

        return $map;
    }

    /**
     * @param array<string, Block> $classBlocks
     *
     * @return list<string>
     */
    private static function expandRequiredNewClassNames(
        Block $classBody,
        int $valueSlot,
        array $classBlocks
    ): array {
        $required = [];
        $queue = self::collectNewLiteralClassNamesInConstInit($classBody, $valueSlot);
        while ([] !== $queue) {
            $className = array_shift($queue);
            $lc = strtolower(ltrim($className, '\\'));
            if (isset($required[$lc])) {
                continue;
            }
            $required[$lc] = $className;
            $body = $classBlocks[$lc] ?? null;
            if (null === $body) {
                continue;
            }
            foreach (self::collectAllNewLiteralClassNamesInClassBody($body) as $dep) {
                $depLc = strtolower(ltrim($dep, '\\'));
                if (!isset($required[$depLc])) {
                    $queue[] = $dep;
                }
            }
        }

        return array_values($required);
    }

    /**
     * @return list<string>
     */
    private static function collectNewLiteralClassNamesInConstInit(Block $classBody, int $valueSlot): array
    {
        $names = [];
        foreach ($classBody->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS_CONST === $op->type && $valueSlot === $op->arg2) {
                break;
            }
            if (OpCode::TYPE_NEW === $op->type) {
                $classOp = $classBody->getOperand($op->arg2);
                if ($classOp instanceof Literal && is_string($classOp->value) && '' !== $classOp->value) {
                    $names[] = $classOp->value;
                }
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function collectAllNewLiteralClassNamesInClassBody(Block $classBody): array
    {
        $names = [];
        foreach ($classBody->opCodes as $op) {
            if (OpCode::TYPE_NEW !== $op->type) {
                continue;
            }
            $classOp = $classBody->getOperand($op->arg2);
            if ($classOp instanceof Literal && is_string($classOp->value) && '' !== $classOp->value) {
                $names[] = $classOp->value;
            }
        }

        return $names;
    }
}
