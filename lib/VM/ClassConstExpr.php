<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\Frame;
use PHPCompiler\OpCode;

/**
 * Evaluate scalar class constant expressions at class-compile time (#3567).
 *
 * Reference: Zend/zend_compile.c — zend_compile_const_expr(), zend_const_expr_to_zval()
 * Array and string {@see OpCode::TYPE_ARRAY_DIM_FETCH} are both allowed (#5465, #24927).
 * Scalar/(array) casts on PHP 8.5+ (#24947); compile gate rejects them on ≤8.4 (#24905).
 */
final class ClassConstExpr
{
    public static function isSupportedOpcode(int $type): bool
    {
        return match ($type) {
            OpCode::TYPE_PLUS,
            OpCode::TYPE_MINUS,
            OpCode::TYPE_MUL,
            OpCode::TYPE_DIV,
            OpCode::TYPE_MODULO,
            OpCode::TYPE_POW,
            OpCode::TYPE_BITWISE_AND,
            OpCode::TYPE_BITWISE_OR,
            OpCode::TYPE_BITWISE_XOR,
            OpCode::TYPE_SHIFT_LEFT,
            OpCode::TYPE_SHIFT_RIGHT,
            OpCode::TYPE_UNARY_MINUS,
            OpCode::TYPE_UNARY_PLUS,
            OpCode::TYPE_BITWISE_NOT,
            OpCode::TYPE_BOOLEAN_NOT,
            OpCode::TYPE_CONCAT,
            OpCode::TYPE_SMALLER,
            OpCode::TYPE_GREATER,
            OpCode::TYPE_SMALLER_OR_EQUAL,
            OpCode::TYPE_GREATER_OR_EQUAL,
            OpCode::TYPE_SPACESHIP,
            OpCode::TYPE_EQUAL,
            OpCode::TYPE_NOT_EQUAL,
            OpCode::TYPE_IDENTICAL,
            OpCode::TYPE_NOT_IDENTICAL,
            OpCode::TYPE_LOGICAL_XOR,
            OpCode::TYPE_CONST_FETCH,
            OpCode::TYPE_CLASS_CONST_FETCH,
            OpCode::TYPE_ARRAY_DIM_FETCH,
            OpCode::TYPE_PROPERTY_FETCH,
            OpCode::TYPE_PROPERTY_FETCH_WRITE,
            OpCode::TYPE_CAST_BOOL,
            OpCode::TYPE_CAST_INT,
            OpCode::TYPE_CAST_FLOAT,
            OpCode::TYPE_CAST_STRING,
            OpCode::TYPE_CAST_ARRAY => true,
            default => false,
        };
    }

    public static function execute(Context $context, Frame $frame, Block $block, OpCode $op, ClassEntry $entry): void
    {
        switch ($op->type) {
            case OpCode::TYPE_PLUS:
            case OpCode::TYPE_MINUS:
            case OpCode::TYPE_MUL:
            case OpCode::TYPE_DIV:
            case OpCode::TYPE_MODULO:
            case OpCode::TYPE_POW:
                $frame->scope[$op->arg1]->numericOp(
                    $op->type,
                    $frame->scope[$op->arg2],
                    $frame->scope[$op->arg3]
                );
                break;
            case OpCode::TYPE_BITWISE_AND:
            case OpCode::TYPE_BITWISE_OR:
            case OpCode::TYPE_BITWISE_XOR:
            case OpCode::TYPE_SHIFT_LEFT:
            case OpCode::TYPE_SHIFT_RIGHT:
                $frame->scope[$op->arg1]->bitwiseOp(
                    $op->type,
                    $frame->scope[$op->arg2],
                    $frame->scope[$op->arg3]
                );
                break;
            case OpCode::TYPE_UNARY_MINUS:
            case OpCode::TYPE_UNARY_PLUS:
            case OpCode::TYPE_BITWISE_NOT:
            case OpCode::TYPE_BOOLEAN_NOT:
                $frame->scope[$op->arg1]->unaryOp($op->type, $frame->scope[$op->arg2]);
                break;
            case OpCode::TYPE_CAST_BOOL:
                $frame->scope[$op->arg1]->castFrom(
                    Variable::TYPE_BOOLEAN,
                    self::resolveValue($frame, $block, (int) $op->arg2)
                );
                break;
            case OpCode::TYPE_CAST_INT:
                $frame->scope[$op->arg1]->castFrom(
                    Variable::TYPE_INTEGER,
                    self::resolveValue($frame, $block, (int) $op->arg2)
                );
                break;
            case OpCode::TYPE_CAST_FLOAT:
                $frame->scope[$op->arg1]->castFrom(
                    Variable::TYPE_FLOAT,
                    self::resolveValue($frame, $block, (int) $op->arg2)
                );
                break;
            case OpCode::TYPE_CAST_STRING:
                $frame->scope[$op->arg1]->castFrom(
                    Variable::TYPE_STRING,
                    self::resolveValue($frame, $block, (int) $op->arg2)
                );
                break;
            case OpCode::TYPE_CAST_ARRAY:
                $frame->scope[$op->arg1]->copyFrom(
                    CastSupport::toArray(
                        self::resolveValue($frame, $block, (int) $op->arg2),
                        $context->classes
                    )
                );
                break;
            case OpCode::TYPE_CONCAT:
                $frame->scope[$op->arg1]->string(
                    $frame->scope[$op->arg2]->toString()
                    . $frame->scope[$op->arg3]->toString()
                );
                break;
            case OpCode::TYPE_SMALLER:
            case OpCode::TYPE_GREATER:
            case OpCode::TYPE_SMALLER_OR_EQUAL:
            case OpCode::TYPE_GREATER_OR_EQUAL:
                $frame->scope[$op->arg1]->compareOp(
                    $op->type,
                    $frame->scope[$op->arg2],
                    $frame->scope[$op->arg3]
                );
                break;
            case OpCode::TYPE_SPACESHIP:
                // Zend/zend_operators.c compare_function / spaceship (#24928).
                $frame->scope[$op->arg1]->spaceshipOp(
                    $frame->scope[$op->arg2],
                    $frame->scope[$op->arg3]
                );
                break;
            case OpCode::TYPE_IDENTICAL:
                $frame->scope[$op->arg1]->bool(
                    $frame->scope[$op->arg2]->identicalTo($frame->scope[$op->arg3])
                );
                break;
            case OpCode::TYPE_NOT_IDENTICAL:
                $frame->scope[$op->arg1]->bool(
                    !$frame->scope[$op->arg2]->identicalTo($frame->scope[$op->arg3])
                );
                break;
            case OpCode::TYPE_EQUAL:
                $frame->scope[$op->arg1]->bool(
                    $frame->scope[$op->arg2]->equals($frame->scope[$op->arg3])
                );
                break;
            case OpCode::TYPE_NOT_EQUAL:
                $frame->scope[$op->arg1]->bool(
                    !$frame->scope[$op->arg2]->equals($frame->scope[$op->arg3])
                );
                break;
            case OpCode::TYPE_LOGICAL_XOR:
                $frame->scope[$op->arg1]->bool(
                    $frame->scope[$op->arg2]->toBool() !== $frame->scope[$op->arg3]->toBool()
                );
                break;
            case OpCode::TYPE_CONST_FETCH:
                self::executeConstFetch($context, $frame, $op);
                break;
            case OpCode::TYPE_CLASS_CONST_FETCH:
                self::executeClassConstFetch($context, $frame, $op, $entry);
                break;
            case OpCode::TYPE_ARRAY_DIM_FETCH:
                self::executeArrayDimFetch($context, $frame, $block, $op);
                break;
            case OpCode::TYPE_PROPERTY_FETCH:
            case OpCode::TYPE_PROPERTY_FETCH_WRITE:
                self::executePropertyFetch($context, $frame, $op);
                break;
            default:
                throw new \LogicException(
                    'Unsupported class const expression opcode: '.opcode_type_name($op->type)
                );
        }
    }

    public static function resolveValue(Frame $frame, Block $block, int $slot): Variable
    {
        if (isset($block->constants[$slot])) {
            $value = new Variable();
            $value->copyFrom($block->constants[$slot]);

            return $value;
        }
        if (!isset($frame->scope[$slot])) {
            throw new \LogicException('Class constant value must be a compile-time constant');
        }
        $value = new Variable();
        $value->copyFrom($frame->scope[$slot]);

        return $value;
    }

    private static function executeConstFetch(Context $context, Frame $frame, OpCode $op): void
    {
        $value = null;
        if (null !== $op->arg3) {
            $value = $context->constantFetch($frame->scope[$op->arg3]->toString());
        }
        if (null === $value) {
            $value = $context->constantFetch($frame->scope[$op->arg2]->toString());
        }
        if (null === $value) {
            $constName = null !== $op->arg3
                ? $frame->scope[$op->arg3]->toString()
                : $frame->scope[$op->arg2]->toString();
            throw new \Error(sprintf('Undefined constant "%s"', $constName));
        }
        $frame->scope[$op->arg1]->copyFrom($value);
    }

    private static function executeClassConstFetch(
        Context $context,
        Frame $frame,
        OpCode $op,
        ClassEntry $entry
    ): void {
        $className = $frame->scope[$op->arg2]->toString();
        $lcClass = self::resolveClassName($context, $entry, $className);
        $constNameRaw = $frame->scope[$op->arg3]->toString();
        // Case-sensitive class constant / enum case key (#25910, #25929).
        $constName = \PHPCompiler\ClassConstName::key($constNameRaw);
        $constIsClass = 'class' === strtolower($constNameRaw);
        $fetchClassDisplay = $op->classConstFetchScopeKeyword ?? $className;

        if ($lcClass === strtolower($entry->name)) {
            self::fetchFromDeclaringClass(
                $context,
                $frame,
                $op,
                $entry,
                $constName,
                $constIsClass,
                $fetchClassDisplay
            );

            return;
        }

        if (!isset($context->classes[$lcClass])) {
            if ('self' !== strtolower($className) && 'static' !== strtolower($className)) {
                $context->autoloadClass($className);
                if (!isset($context->classes[$lcClass]) && !str_contains($className, '\\')) {
                    $qualified = self::qualifyClassNameForConstFetch($className, $entry);
                    if ($qualified !== $className) {
                        $context->autoloadClass($qualified);
                        $lcClass = strtolower($qualified);
                    }
                }
            }
        }
        if (!isset($context->classes[$lcClass])) {
            if ($constIsClass
                && !\in_array(strtolower($className), ['self', 'static', 'parent'], true)) {
                // X::class is a pure name literal — Zend resolves it without the
                // class existing. Native 8.3+ names (DateException, …) reach here
                // on the 8.2 reference profile before/without any declaration (#16828).
                $frame->scope[$op->arg1]->string(ltrim($className, '\\'));

                return;
            }
            foreach (self::classNameCandidatesForConstFetch($className, $entry) as $candidate) {
                if (self::tryFetchNativePhpClassConstant($candidate, $constNameRaw, $frame->scope[$op->arg1])) {
                    return;
                }
            }
            throw new ClassConstForwardReferenceException($className, $constName);
        }

        $classEntry = $context->classes[$lcClass];
        if ($constIsClass) {
            $frame->scope[$op->arg1]->string($className);

            return;
        }
        if (!isset($classEntry->constants[$constName])) {
            foreach (self::classNameCandidatesForConstFetch($className, $entry) as $candidate) {
                if (self::tryFetchNativePhpClassConstant($candidate, $constNameRaw, $frame->scope[$op->arg1])) {
                    return;
                }
            }
            throw new \LogicException("Undefined constant {$className}::{$constName}");
        }
        if (EnumCaseSupport::tryMaterializeEnumCaseConstantFetch($classEntry, $constName, $frame->scope[$op->arg1])) {
            return;
        }
        $frame->scope[$op->arg1]->copyFrom($classEntry->constants[$constName]);
    }

    private static function fetchFromDeclaringClass(
        Context $context,
        Frame $frame,
        OpCode $op,
        ClassEntry $entry,
        string $constName,
        bool $constIsClass = false,
        string $fetchClassName = 'self'
    ): void {
        if ($constIsClass || 'class' === strtolower($constName)) {
            $frame->scope[$op->arg1]->string($entry->name);

            return;
        }
        if (!isset($entry->constants[$constName])) {
            $inherited = self::resolveInheritedConstantInDeclaringClass($context, $entry, $constName);
            if (null !== $inherited) {
                $frame->scope[$op->arg1]->copyFrom($inherited);

                return;
            }
            if (isset($entry->visitedConstNames[$constName])) {
                throw new \Error(self::selfReferencingConstantMessage($entry, $constName, $fetchClassName));
            }
            if (
                null !== $entry->forwardDeclaredConstNames
                && isset($entry->forwardDeclaredConstNames[$constName])
            ) {
                // Nested fetch during lazy materialization — evaluate now with visited mark
                // (zend_get_class_constant_ex / IS_CONSTANT_VISITED; #31837).
                if ($entry->lazyConstMaterialize || [] !== $entry->visitedConstNames) {
                    $vm = $context->runtime->vm;
                    if (null !== $vm) {
                        $vm->materializePendingClassConstant($entry, $constName, true, $fetchClassName);
                        if (isset($entry->constants[$constName])) {
                            if (EnumCaseSupport::tryMaterializeEnumCaseConstantFetch(
                                $entry,
                                $constName,
                                $frame->scope[$op->arg1]
                            )) {
                                return;
                            }
                            $frame->scope[$op->arg1]->copyFrom($entry->constants[$constName]);

                            return;
                        }
                    }
                }
                throw new ClassConstForwardReferenceException($entry->name, $constName);
            }
            $display = $entry->constNames[$constName] ?? $constName;
            throw new \LogicException(
                "Undefined constant {$entry->name}::{$display}"
            );
        }
        if (EnumCaseSupport::tryMaterializeEnumCaseConstantFetch($entry, $constName, $frame->scope[$op->arg1])) {
            return;
        }
        $frame->scope[$op->arg1]->copyFrom($entry->constants[$constName]);
    }

    /**
     * Zend/zend_constants.c — "Cannot declare self-referencing constant %s::%s".
     */
    public static function selfReferencingConstantMessage(
        ClassEntry $entry,
        string $constName,
        string $fetchClassName = 'self'
    ): string {
        $display = $entry->constNames[$constName]
            ?? self::pendingConstCanonicalName($entry, $constName)
            ?? $constName;

        return "Cannot declare self-referencing constant {$fetchClassName}::{$display}";
    }

    private static function pendingConstCanonicalName(ClassEntry $entry, string $constName): ?string
    {
        $pending = $entry->pendingConstMaterialization;
        if (null === $pending || !isset($pending['segments'][$constName])) {
            return null;
        }
        $declareOp = $pending['classBodyOps'][$pending['segments'][$constName]['declareIndex']];
        if (!isset($pending['frame']->scope[$declareOp->arg1])) {
            return null;
        }

        return $pending['frame']->scope[$declareOp->arg1]->toString();
    }

    /**
     * Resolve inherited class constants for {@code self::} in the declaring class (#13532, zend_constants.c).
     */
    private static function resolveInheritedConstantInDeclaringClass(
        Context $context,
        ClassEntry $entry,
        string $constName
    ): ?Variable {
        foreach ($entry->interfaces as $ifaceLc) {
            if (!isset($context->classes[$ifaceLc])) {
                continue;
            }
            $iface = $context->classes[$ifaceLc];
            if (isset($iface->constants[$constName])) {
                return $iface->constants[$constName];
            }
            $fromIface = self::resolveInheritedConstantInDeclaringClass($context, $iface, $constName);
            if (null !== $fromIface) {
                return $fromIface;
            }
        }
        if (null === $entry->parentLc || !isset($context->classes[$entry->parentLc])) {
            return null;
        }
        $parent = $context->classes[$entry->parentLc];
        if (isset($parent->constants[$constName])) {
            $vis = $parent->constVisibility[$constName] ?? \PHPCfg\Func::FLAG_PUBLIC;
            if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                return self::resolveInheritedConstantInDeclaringClass($context, $parent, $constName);
            }

            return $parent->constants[$constName];
        }

        return self::resolveInheritedConstantInDeclaringClass($context, $parent, $constName);
    }

    private static function executePropertyFetch(Context $context, Frame $frame, OpCode $op): void
    {
        $obj = $frame->scope[$op->arg2]->resolveIndirect();
        $propName = $frame->scope[$op->arg3]->toString();
        if (Variable::TYPE_ENUM_CASE === $obj->type) {
            $prop = $obj->toEnumCase()->fetchProperty($propName, $context, $frame);
            $frame->scope[$op->arg1]->copyFrom($prop);

            return;
        }
        if (Variable::TYPE_OBJECT === $obj->type && EnumCaseSupport::isEnumCase($obj->toObject())) {
            $prop = EnumCaseSupport::getProperty($obj->toObject(), $propName, $context, $frame);
            $frame->scope[$op->arg1]->copyFrom($prop);

            return;
        }

        throw new \LogicException(
            'Property fetch in class constant expression requires enum case receiver'
        );
    }

    private static function executeArrayDimFetch(
        Context $context,
        Frame $frame,
        Block $block,
        OpCode $op
    ): void {
        if (null === $op->arg3) {
            throw new \LogicException('[] append is not supported in class constant expressions');
        }
        $container = self::resolveValue($frame, $block, $op->arg2);
        $dim = self::resolveValue($frame, $block, $op->arg3);
        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;

        // php-src zend_ast_evaluate / ZEND_AST_DIM: string operands yield a single-byte string (#24927).
        if (Variable::TYPE_STRING === $container->type) {
            $byteIndex = Variable::stringOffsetIndexFromDim(
                $dim,
                $context->errors,
                $context,
                $frame,
                $scriptFile
            );
            $readShell = new Variable(Variable::TYPE_STRING_OFFSET);
            $readShell->stringOffset(
                $container,
                $byteIndex,
                $context->errors,
                $context,
                $frame,
                $scriptFile
            );
            $frame->scope[$op->arg1]->string($readShell->toString());

            return;
        }

        if (Variable::TYPE_ARRAY !== $container->type) {
            if (TypeCheck::isScalarUsedAsArray($container)) {
                throw new \Error(TypeCheck::SCALAR_USED_AS_ARRAY_MESSAGE);
            }
            throw new \LogicException('[] is only supported for arrays in class constant expressions');
        }
        $dimVar = new Variable();
        $dimVar->copyFrom($dim);
        $table = $container->toArray();
        if (!$table->keyExists($dimVar)) {
            $context->errors->undefinedArrayKey(
                $dimVar,
                $context,
                $frame,
                $scriptFile
            );
            $frame->scope[$op->arg1]->null();

            return;
        }
        $elem = $table->findVariable($dimVar, false);
        if (null === $elem) {
            $frame->scope[$op->arg1]->null();

            return;
        }
        $frame->scope[$op->arg1]->copyFrom($elem->resolveIndirect());
    }

    /**
     * @return list<string>
     */
    private static function classNameCandidatesForConstFetch(string $className, ClassEntry $entry): array
    {
        $candidates = [ltrim($className, '\\')];
        if (!str_contains($className, '\\')) {
            $qualified = self::qualifyClassNameForConstFetch($className, $entry);
            if ($qualified !== $className) {
                $candidates[] = $qualified;
            }
        }

        return array_values(array_unique($candidates));
    }

    private static function qualifyClassNameForConstFetch(string $className, ClassEntry $entry): string
    {
        if ('' === $className || str_contains($className, '\\')) {
            return ltrim($className, '\\');
        }
        $declaring = ltrim($entry->name, '\\');
        $nsPos = strrpos($declaring, '\\');
        if (false === $nsPos) {
            return $className;
        }

        return substr($declaring, 0, $nsPos).'\\'.$className;
    }

    /**
     * Fold class constants from already-loaded native PHP classes (bootstrap spine; #6221).
     */
    private static function tryFetchNativePhpClassConstant(
        string $className,
        string $constName,
        Variable $dest
    ): bool {
        $fqcn = ltrim($className, '\\');
        if ('class' === strtolower($constName)) {
            if (!class_exists($fqcn, true)) {
                return false;
            }
            $dest->string($fqcn);

            return true;
        }
        if (!class_exists($fqcn, true)) {
            return false;
        }
        try {
            $ref = new \ReflectionClassConstant($fqcn, $constName);
        } catch (\ReflectionException) {
            return false;
        }
        $raw = $ref->getValue();
        $value = self::variableFromNativePhpValue($raw);
        if (null === $value) {
            return false;
        }
        $dest->copyFrom($value);

        return true;
    }

    /**
     * @return Variable|null
     */
    private static function variableFromNativePhpValue(mixed $raw): ?Variable
    {
        if (\is_int($raw)) {
            $value = new Variable();
            $value->int($raw);

            return $value;
        }
        if (\is_bool($raw)) {
            $value = new Variable();
            $value->bool($raw);

            return $value;
        }
        if (\is_float($raw)) {
            $value = new Variable();
            $value->float($raw);

            return $value;
        }
        if (\is_string($raw)) {
            $value = new Variable();
            $value->string($raw);

            return $value;
        }
        if (\is_array($raw)) {
            $table = new HashTable();
            foreach ($raw as $key => $item) {
                $elem = self::variableFromNativePhpValue($item);
                if (null === $elem) {
                    return null;
                }
                if (\is_int($key)) {
                    $table->updateIndex($key, $elem);
                } elseif (\is_string($key)) {
                    $table->update($key, $elem);
                } else {
                    return null;
                }
            }
            $value = new Variable();
            $value->array($table);

            return $value;
        }

        return null;
    }

    private static function resolveClassName(Context $context, ClassEntry $entry, string $className): string
    {
        $lcClass = strtolower($className);
        if ('self' === $lcClass || $lcClass === strtolower($entry->name)) {
            return strtolower($entry->name);
        }
        if ('parent' === $lcClass) {
            if (null === $entry->parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $entry->parentLc;
        }
        if ('static' === $lcClass) {
            return strtolower($entry->name);
        }

        return $lcClass;
    }
}
