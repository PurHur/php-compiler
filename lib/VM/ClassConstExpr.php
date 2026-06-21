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
            OpCode::TYPE_CONST_FETCH,
            OpCode::TYPE_CLASS_CONST_FETCH,
            OpCode::TYPE_ARRAY_DIM_FETCH,
            OpCode::TYPE_PROPERTY_FETCH => true,
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
            case OpCode::TYPE_CONCAT:
                $frame->scope[$op->arg1]->string(
                    $frame->scope[$op->arg2]->toString()
                    . $frame->scope[$op->arg3]->toString()
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
        $constName = strtolower($constNameRaw);

        if ($lcClass === strtolower($entry->name)) {
            self::fetchFromDeclaringClass($frame, $op, $entry, $constName);

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
            foreach (self::classNameCandidatesForConstFetch($className, $entry) as $candidate) {
                if (self::tryFetchNativePhpClassConstant($candidate, $constNameRaw, $frame->scope[$op->arg1])) {
                    return;
                }
            }
            throw new ClassConstForwardReferenceException($className, $constName);
        }

        $classEntry = $context->classes[$lcClass];
        if ('class' === $constName) {
            $frame->scope[$op->arg1]->string($classEntry->name);

            return;
        }
        if (!isset($classEntry->constants[$constName])) {
            foreach (self::classNameCandidatesForConstFetch($className, $entry) as $candidate) {
                if (self::tryFetchNativePhpClassConstant($candidate, $constNameRaw, $frame->scope[$op->arg1])) {
                    return;
                }
            }
            throw new \LogicException("Undefined class constant {$className}::{$constName}");
        }
        if (EnumCaseSupport::tryMaterializeEnumCaseConstantFetch($classEntry, $constName, $frame->scope[$op->arg1])) {
            return;
        }
        $frame->scope[$op->arg1]->copyFrom($classEntry->constants[$constName]);
    }

    private static function fetchFromDeclaringClass(
        Frame $frame,
        OpCode $op,
        ClassEntry $entry,
        string $constName
    ): void {
        if ('class' === $constName) {
            $frame->scope[$op->arg1]->string($entry->name);

            return;
        }
        if (!isset($entry->constants[$constName])) {
            if (
                null !== $entry->forwardDeclaredConstNames
                && isset($entry->forwardDeclaredConstNames[$constName])
            ) {
                throw new ClassConstForwardReferenceException($entry->name, $constName);
            }
            $display = $entry->constNames[$constName] ?? $constName;
            throw new \LogicException(
                "Undefined class constant {$entry->name}::{$display}"
            );
        }
        if (EnumCaseSupport::tryMaterializeEnumCaseConstantFetch($entry, $constName, $frame->scope[$op->arg1])) {
            return;
        }
        $frame->scope[$op->arg1]->copyFrom($entry->constants[$constName]);
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
        if (Variable::TYPE_ARRAY !== $container->type) {
            if (TypeCheck::isScalarUsedAsArray($container)) {
                throw new \Error(TypeCheck::SCALAR_USED_AS_ARRAY_MESSAGE);
            }
            throw new \LogicException('[] is only supported for arrays in class constant expressions');
        }
        $dim = self::resolveValue($frame, $block, $op->arg3);
        $dimVar = new Variable();
        $dimVar->copyFrom($dim);
        $table = $container->toArray();
        if (!$table->keyExists($dimVar)) {
            $context->errors->undefinedArrayKey(
                $dimVar,
                $context,
                $frame,
                '' !== $frame->scriptPath ? $frame->scriptPath : null
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
