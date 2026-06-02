<?php

declare(strict_types=1);

/**
 * LLVM lowering for dynamic class constant fetch `Class::{$name}` (issue #3150).
 *
 * php-src: {@see https://github.com/php/php-src/blob/master/Zend/zend_compile.c}
 * runtime lookup by name in {@see https://github.com/php/php-src/blob/master/Zend/zend_execute.c}
 */

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReadonlyRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCfg\Operand;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class ClassConstFetchHelper
{
    /**
     * Lower `$expr::class` when the class operand is a runtime expression (#4241).
     *
     * @return Value {@see __string__*}
     */
    public static function emitExprClassPseudoConst(Object_ $objectType, Variable $classVar): Value
    {
        $context = $objectType->jitContext();
        if (Variable::TYPE_OBJECT === $classVar->type) {
            return ReflectionBuiltinHelper::getClassName($context, $classVar);
        }
        $label = self::compileTimeNonObjectTypeLabel($classVar);
        if (null !== $label) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitRaise(
                $context,
                'Cannot use "::class" on value of type '.$label
            );

            return $context->builder->load($context->constantStringFromString(''));
        }
        if (Variable::TYPE_VALUE === $classVar->type) {
            return self::emitValueBoxExprClassPseudoConst($objectType, $classVar);
        }

        throw new \LogicException('Unsupported operand for expression ::class in JIT');
    }

    /**
     * Lower `$operand::class` when the class operand is not a compile-time literal (#4179).
     *
     * @return Value {@see __string__*}
     */
    public static function emitClassPseudoConstStringValue(
        Object_ $objectType,
        Block $block,
        Variable $classVar
    ): Value {
        $literal = JitStringArg::compileTimeLiteral($classVar);
        if (null !== $literal) {
            $resolved = self::resolveJitClassNameString($objectType, $block, $literal);
            $classId = $objectType->lookup($resolved);

            return $objectType->jitContext()->builder->load(
                $objectType->jitContext()->constantStringFromString($objectType->classNameForId($classId))
            );
        }

        $context = $objectType->jitContext();
        $nameStr = JitStringArg::lowerDominating($context, $classVar, '::class class operand');
        $resolvedStr = self::emitScopeResolveClassNameString($objectType, $block, $nameStr);

        return self::emitCanonicalClassNameFromResolved($objectType, $resolvedStr);
    }

    public static function fetchDynamic(
        Object_ $objectType,
        int $classId,
        Variable $nameVar,
        Operand $classOp
    ): Variable {
        $literal = JitStringArg::compileTimeLiteral($nameVar);
        if (null !== $literal) {
            if ('class' === strtolower($literal)) {
                return self::classPseudoConst($objectType, $classId);
            }

            return $objectType->classConstFetch($classId, $literal);
        }

        $context = $objectType->jitContext();
        self::ensureStrCaseCmp($context);
        ReadonlyRaise::ensureLinked($context);

        $nativeName = JitStringArg::lower($context, $nameVar, 'class constant name');
        $resultSlot = JitValueBox::alloc($context);
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $merge = $fn->appendBasicBlock('class_const_dyn_merge');
        $fail = $fn->appendBasicBlock('class_const_dyn_fail');

        $classPseudo = $context->builder->load($context->constantStringFromString('class'));
        $context->builder->positionAtEnd($entry);
        $isClass = $context->builder->call(
            $context->lookupFunction('strcasecmp'),
            self::stringDataPtr($context, $nativeName),
            self::stringDataPtr($context, $classPseudo)
        );
        $i32 = $context->getTypeFromString('int32');
        $classMatch = $fn->appendBasicBlock('class_const_dyn_class');
        $constChain = $fn->appendBasicBlock('class_const_dyn_chain');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $isClass, $i32->constInt(0, false)),
            $classMatch,
            $constChain
        );

        $context->builder->positionAtEnd($classMatch);
        $className = $objectType->classNameForId($classId);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $resultSlot),
            $context->builder->load($context->constantStringFromString($className))
        );
        $context->builder->branch($merge);

        $constants = $objectType->classConstantsForId($classId);
        $checkBlock = $constChain;
        $n = count($constants);
        $context->builder->positionAtEnd($constChain);
        if (0 === $n) {
            $context->builder->branch($fail);
        } else {
            foreach ($constants as $i => [$constKey, $entry]) {
                $nextCheck = ($i < $n - 1)
                    ? $fn->appendBasicBlock('class_const_dyn_try_'.$constKey)
                    : $fail;
                $matchBlock = $fn->appendBasicBlock('class_const_dyn_match_'.$constKey);
                $context->builder->positionAtEnd($checkBlock);
                $keyGlobal = $context->builder->load($context->constantStringFromString($constKey));
                $cmp = $context->builder->call(
                    $context->lookupFunction('strcasecmp'),
                    self::stringDataPtr($context, $nativeName),
                    self::stringDataPtr($context, $keyGlobal)
                );
                $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
                $context->builder->branchIf($isMatch, $matchBlock, $nextCheck);

                $context->builder->positionAtEnd($matchBlock);
                self::writeConstEntry($context, $resultSlot, $entry);
                $context->builder->branch($merge);
                $checkBlock = $nextCheck;
            }
        }

        $context->builder->positionAtEnd($fail);
        $displayClass = self::displayClassName($objectType, $classId, $classOp);
        $message = "Undefined class constant {$displayClass}::*";
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_logic_exception'),
            self::messageDataPtr($context, $message),
            $context->constantFromInteger(strlen($message), 'size_t')
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($merge);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $resultSlot
        );
    }

    private static function classPseudoConst(Object_ $objectType, int $classId): Variable
    {
        $context = $objectType->jitContext();
        $lit = new Operand\Literal($objectType->classNameForId($classId));
        $lit->type = \PHPTypes\Type::string();

        return Variable::fromLiteral($context, $lit);
    }

    private static function resolveJitClassNameString(Object_ $objectType, Block $block, string $className): string
    {
        $lc = strtolower($className);
        if ('self' === $lc) {
            $scope = self::jitScopeClassName($objectType, $block);
            if (null === $scope) {
                throw new \LogicException('self:: used outside of class scope');
            }

            return $scope;
        }
        if ('static' === $lc) {
            $scope = self::jitLateStaticClassName($objectType, $block);
            if (null === $scope) {
                throw new \LogicException('static:: used outside of class scope');
            }

            return $scope;
        }
        if ('parent' === $lc) {
            $scope = self::jitScopeClassName($objectType, $block);
            if (null === $scope) {
                throw new \LogicException('parent:: used outside of class scope');
            }
            $parent = $objectType->parentClassDisplayName($scope);
            if (null === $parent) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $parent;
        }

        return $className;
    }

    private static function jitScopeClassName(Object_ $objectType, Block $block): ?string
    {
        if (null !== $block->func && null !== $block->func->class) {
            return $block->func->class->value;
        }
        $scopeName = $objectType->jitContext()->scope->className ?? '';
        if ('' !== $scopeName) {
            return $scopeName;
        }

        return null;
    }

    private static function jitLateStaticClassName(Object_ $objectType, Block $block): ?string
    {
        $called = $objectType->jitContext()->scope->calledClassName ?? '';
        if ('' !== $called) {
            return $called;
        }

        return self::jitScopeClassName($objectType, $block);
    }

    /**
     * @return Value {@see __string__*}
     */
    private static function emitScopeResolveClassNameString(
        Object_ $objectType,
        Block $block,
        Value $nameStr
    ): Value {
        $context = $objectType->jitContext();
        $scopeClass = self::jitScopeClassName($objectType, $block);
        if (null === $scopeClass) {
            return $nameStr;
        }
        self::ensureStrCaseCmp($context);
        $i32 = $context->getTypeFromString('int32');
        $result = $nameStr;

        foreach (
            [
                ['self', $scopeClass],
                ['static', self::jitLateStaticClassName($objectType, $block) ?? $scopeClass],
            ] as [$keyword, $resolvedName]
        ) {
            $keyGlobal = $context->builder->load($context->constantStringFromString($keyword));
            $cmp = $context->builder->call(
                $context->lookupFunction('strcasecmp'),
                self::stringDataPtr($context, $nameStr),
                self::stringDataPtr($context, $keyGlobal)
            );
            $isKw = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $resolvedGlobal = $context->builder->load(
                $context->constantStringFromString($resolvedName)
            );
            $result = $context->builder->select($isKw, $resolvedGlobal, $result);
        }

        $parentName = $objectType->parentClassDisplayName($scopeClass);
        if (null !== $parentName) {
            $parentKey = $context->builder->load($context->constantStringFromString('parent'));
            $cmp = $context->builder->call(
                $context->lookupFunction('strcasecmp'),
                self::stringDataPtr($context, $nameStr),
                self::stringDataPtr($context, $parentKey)
            );
            $isParent = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $parentGlobal = $context->builder->load(
                $context->constantStringFromString($parentName)
            );
            $result = $context->builder->select($isParent, $parentGlobal, $result);
        }

        return $result;
    }

    /**
     * @return Value {@see __string__*}
     */
    private static function emitCanonicalClassNameFromResolved(Object_ $objectType, Value $resolvedNameStr): Value
    {
        $context = $objectType->jitContext();
        self::ensureStrCaseCmp($context);
        $i32 = $context->getTypeFromString('int32');
        $strMap = $context->structFieldMap['__string__'];
        $result = $context->builder->load($context->constantStringFromString(''));
        foreach ($objectType->allClassNamesById() as $canonical) {
            $lcGlobal = $context->builder->load(
                $context->constantStringFromString(strtolower(ltrim($canonical, '\\')))
            );
            $cmp = $context->builder->call(
                $context->lookupFunction('strcasecmp'),
                self::stringDataPtr($context, $resolvedNameStr),
                self::stringDataPtr($context, $lcGlobal)
            );
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $cand = $context->builder->load($context->constantStringFromString($canonical));
            $result = $context->builder->select($isMatch, $cand, $result);
        }

        $len = $context->builder->load($context->builder->structGep($result, $strMap['length']));
        $sizeT = $context->getTypeFromString('size_t');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $sizeT->constInt(0, false));
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $ok = $fn->appendBasicBlock('class_pseudo_const_ok');
        $fail = $fn->appendBasicBlock('class_pseudo_const_fail');
        $merge = $fn->appendBasicBlock('class_pseudo_const_merge');
        $context->builder->branchIf($isEmpty, $fail, $ok);

        $context->builder->positionAtEnd($fail);
        $message = 'Unknown class for constant fetch';
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_logic_exception'),
            self::messageDataPtr($context, $message),
            $context->constantFromInteger(strlen($message), 'size_t')
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($ok);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return $result;
    }

    /**
     * @param array{type: int, value: int|float|bool|string|null} $entry
     */
    private static function writeConstEntry(Context $context, Value $slot, array $entry): void
    {
        switch ($entry['type']) {
            case Variable::TYPE_NATIVE_LONG:
                JitValueBox::writeLong(
                    $context,
                    $slot,
                    $context->constantFromInteger((int) $entry['value'])
                );
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    JitValueBox::pointer($context, $slot),
                    $context->getTypeFromString('double')->constReal((float) $entry['value'])
                );
                break;
            case Variable::TYPE_NATIVE_BOOL:
                $context->builder->call(
                    $context->lookupFunction('__value__writeBool'),
                    JitValueBox::pointer($context, $slot),
                    $context->constantFromBool((bool) $entry['value'])
                );
                break;
            case Variable::TYPE_STRING:
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    JitValueBox::pointer($context, $slot),
                    $context->builder->load($context->constantStringFromString((string) $entry['value']))
                );
                break;
            case Variable::TYPE_NULL:
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $slot)
                );
                break;
            default:
                throw new \LogicException('Unsupported class constant type for dynamic JIT fetch');
        }
    }

    private static function displayClassName(Object_ $objectType, int $classId, Operand $classOp): string
    {
        if ($classOp instanceof Operand\Literal) {
            return $classOp->value;
        }

        return $objectType->classNameForId($classId);
    }

    private static function ensureStrCaseCmp(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p);
        try {
            $context->lookupFunction('strcasecmp');
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction('strcasecmp', $ft);
            $context->registerFunction('strcasecmp', $fn);
        }
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strPtr, $map['value']);
    }

    private static function messageDataPtr(Context $context, string $message): Value
    {
        $strPtr = $context->builder->load($context->constantStringFromString($message));
        $strMap = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $strMap['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function compileTimeNonObjectTypeLabel(Variable $classVar): ?string
    {
        return match ($classVar->type) {
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_NATIVE_BOOL => 'bool',
            Variable::TYPE_NULL => 'null',
            default => null,
        };
    }

    /**
     * @return Value {@see __string__*}
     */
    private static function emitValueBoxExprClassPseudoConst(Object_ $objectType, Variable $classVar): Value
    {
        $context = $objectType->jitContext();
        TypeErrorRaise::ensureLinked($context);
        $objMap = $context->structFieldMap['__object__'];
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $ok = $fn->appendBasicBlock('expr_class_pseudo_ok');
        $failEntry = $fn->appendBasicBlock('expr_class_pseudo_fail');
        $merge = $fn->appendBasicBlock('expr_class_pseudo_merge');

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $classVar);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objType = $context->getTypeFromString('__object__*');
        $isObject = $context->builder->icmp(
            Builder::INT_NE,
            $obj,
            $objType->constNull()
        );
        $context->builder->branchIf($isObject, $ok, $failEntry);

        $context->builder->positionAtEnd($ok);
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $nameWhenObject = self::classNameStringFromId($objectType, $classId);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($failEntry);
        self::emitClassPseudoConstTypeErrorFromValueBox($context, $valuePtr);

        $context->builder->positionAtEnd($merge);

        return $nameWhenObject;
    }

    /**
     * @return Value {@see __string__*}
     */
    private static function classNameStringFromId(Object_ $objectType, Value $classId): Value
    {
        $context = $objectType->jitContext();
        $result = $context->builder->load($context->constantStringFromString(''));
        foreach ($objectType->allClassNamesById() as $id => $name) {
            $expected = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $candidate = $context->builder->load($context->constantStringFromString($name));
            $result = $context->builder->select($isId, $candidate, $result);
        }

        return $result;
    }

    private static function emitClassPseudoConstTypeErrorFromValueBox(Context $context, Value $valuePtr): void
    {
        $typeField = $context->structFieldMap['__value__']['type'];
        $kind = $context->builder->load(
            $context->builder->structGep($valuePtr, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $masked = $context->builder->and($kind, $i8->constInt(0x7f, false));
        $fn = BasicBlockHelper::parentFunction($context);
        $checkBlock = $context->builder->getInsertBlock();
        $labels = [
            4 => 'string',
            1 => 'int',
            2 => 'float',
            3 => 'bool',
            0 => 'null',
            6 => 'array',
        ];
        $n = count($labels);
        $i = 0;
        foreach ($labels as $tag => $typeName) {
            ++$i;
            $raiseBlock = $fn->appendBasicBlock('expr_class_pseudo_err_'.$typeName);
            $nextCheck = ($i < $n)
                ? $fn->appendBasicBlock('expr_class_pseudo_try_'.$typeName)
                : $fn->appendBasicBlock('expr_class_pseudo_err_mixed');
            $context->builder->positionAtEnd($checkBlock);
            $isTag = $context->builder->icmp(
                Builder::INT_EQ,
                $masked,
                $i8->constInt($tag, false)
            );
            $context->builder->branchIf($isTag, $raiseBlock, $nextCheck);
            $context->builder->positionAtEnd($raiseBlock);
            TypeErrorRaise::emitRaise(
                $context,
                'Cannot use "::class" on value of type '.$typeName
            );
            $context->builder->returnVoid();
            $checkBlock = $nextCheck;
        }
        $context->builder->positionAtEnd($checkBlock);
        TypeErrorRaise::emitRaise($context, 'Cannot use "::class" on value of type mixed');
        $context->builder->returnVoid();
    }
}
