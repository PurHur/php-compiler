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
use PHPCompiler\PseudoClassScope;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ClassConstFetchRuntime;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\ReadonlyBridge;
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

    public static function fetchLiteralConstWithRuntimeClass(
        Object_ $objectType,
        Block $block,
        Variable $classVar,
        Operand $classOp,
        string $constName,
        ?\PHPCompiler\JIT $jit = null
    ): Variable {
        $classIdVal = self::emitResolveClassId($objectType, $block, $classVar, $classOp);
        $key = strtolower($constName);
        $context = $objectType->jitContext();
        $resultSlot = JitValueBox::alloc($context);
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $merge = $fn->appendBasicBlock('class_const_var_cls_lit_merge');
        $fail = $fn->appendBasicBlock('class_const_var_cls_lit_fail');
        $i64 = $context->getTypeFromString('int64');
        $checkBlock = $entry;
        $hasCandidate = false;
        foreach ($objectType->allClassNamesById() as $id => $_) {
            $constants = $objectType->classConstantsForId($id);
            $entryData = null;
            foreach ($constants as [$constKey, $entry]) {
                if ($constKey === $key) {
                    $entryData = $entry;
                    break;
                }
            }
            if (null === $entryData) {
                continue;
            }
            $hasCandidate = true;
            $matchBlock = $fn->appendBasicBlock('class_const_var_cls_lit_match_'.$id.'_'.$key);
            $nextCheck = $fn->appendBasicBlock('class_const_var_cls_lit_try_'.$id.'_'.$key);
            $context->builder->positionAtEnd($checkBlock);
            $expectedId = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classIdVal, $expectedId);
            $context->builder->branchIf($isId, $matchBlock, $nextCheck);
            $context->builder->positionAtEnd($matchBlock);
            if ($objectType->isTraitClass(strtolower(ltrim($objectType->classNameForId($id), '\\')))
                && !$objectType->isInTraitMethodScopeForTraitId($id, $block)) {
                $classLabel = $objectType->classNameForId($id);
                ErrorRaise::ensureLinked($context);
                ErrorRaise::emitRaise(
                    $context,
                    "Cannot access trait constant {$classLabel}::{$constName} directly"
                );
                $context->builder->branch($merge);
            } else {
                if (null !== $jit) {
                    ClassConstVisibilityJitGuard::emitBeforeFetch($objectType, $jit, $block, $id, $constName);
                    if ($objectType->isEnumClassId($id)) {
                        BackedEnumDuplicateJitGuard::emitBeforeEnumCaseFetch($objectType, $jit, $block, $id);
                    }
                }
                self::writeConstEntryForRuntime($context, $resultSlot, $entryData);
                $context->builder->branch($merge);
            }
            $checkBlock = $nextCheck;
        }
        $context->builder->positionAtEnd($checkBlock);
        if (!$hasCandidate) {
            throw new \LogicException("Undefined class constant for JIT: {$constName}");
        }
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($fail);
        $displayClass = self::displayClassName($objectType, -1, $classOp);
        $message = "Undefined class constant {$displayClass}::{$constName}";
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_logic_exception'),
            self::messageDataPtrForRuntime($context, $message),
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

    public static function fetchDynamicWithRuntimeClass(
        Object_ $objectType,
        Block $block,
        Variable $classVar,
        Variable $nameVar,
        Operand $classOp
    ): Variable {
        $classIdVal = self::emitResolveClassId($objectType, $block, $classVar, $classOp);

        return self::fetchDynamicByClassIdValue($objectType, $classIdVal, $nameVar, $classOp, $block, null);
    }

    public static function fetchDynamic(
        Object_ $objectType,
        int $classId,
        Variable $nameVar,
        Operand $classOp,
        ?Block $block = null,
        ?\PHPCompiler\JIT $jit = null
    ): Variable {
        $literal = JitStringArg::compileTimeLiteral($nameVar);
        if (null !== $literal) {
            if ('class' === strtolower($literal)) {
                return self::classPseudoConst($objectType, $classId);
            }
            if (null !== $block && null !== $jit) {
                ClassConstVisibilityJitGuard::emitBeforeFetch($objectType, $jit, $block, $classId, $literal);
                if ($objectType->isEnumClassId($classId)) {
                    BackedEnumDuplicateJitGuard::emitBeforeEnumCaseFetch($objectType, $jit, $block, $classId);
                }
            }

            return $objectType->classConstFetch($classId, $literal, $block);
        }

        $context = $objectType->jitContext();
        $classIdVal = $context->constantFromInteger($classId, 'int64');

        return self::fetchDynamicByClassIdValue($objectType, $classIdVal, $nameVar, $classOp, $block, $jit);
    }

    /**
     * @return Value int64 class id
     */
    public static function emitResolveClassId(
        Object_ $objectType,
        Block $block,
        Variable $classVar,
        Operand $classOp
    ): Value {
        if (Variable::TYPE_OBJECT === $classVar->type) {
            $context = $objectType->jitContext();
            $objMap = $context->structFieldMap['__object__'];

            return $context->builder->load(
                $context->builder->structGep($classVar->value, $objMap['class_id'])
            );
        }
        $literal = JitStringArg::compileTimeLiteral($classVar);
        if (null !== $literal) {
            $lcLiteral = strtolower(ltrim($literal, '\\'));
            if (
                LateStaticBindingHelper::useRuntimeLateStatic($objectType->jitContext())
                && \in_array($lcLiteral, ['self', 'static', 'parent'], true)
            ) {
                $context = $objectType->jitContext();
                $nameStr = $context->builder->load(
                    $context->constantStringFromString($literal)
                );
                if ('static' === $lcLiteral) {
                    $scopeClass = self::jitScopeClassName($objectType, $block) ?? '';
                    $resolvedStr = LateStaticBindingHelper::emitLateStaticResolvedNameString(
                        $objectType,
                        $block,
                        $scopeClass
                    );
                } else {
                    $resolvedStr = self::emitScopeResolveClassNameString($objectType, $block, $nameStr);
                }

                return self::emitResolveClassIdFromNameString($objectType, $resolvedStr);
            }
            $resolved = self::resolveJitClassNameString($objectType, $block, $literal);
            $id = $objectType->lookup($resolved);

            return $objectType->jitContext()->constantFromInteger($id, 'int64');
        }
        $context = $objectType->jitContext();
        $nameStr = JitStringArg::lowerDominating($context, $classVar, 'class constant class operand');
        $resolvedStr = self::emitScopeResolveClassNameString($objectType, $block, $nameStr);

        return self::emitResolveClassIdFromNameString($objectType, $resolvedStr);
    }

    private static function fetchDynamicByClassIdValue(
        Object_ $objectType,
        Value $classIdVal,
        Variable $nameVar,
        Operand $classOp,
        ?Block $block = null,
        ?\PHPCompiler\JIT $jit = null
    ): Variable {
        return ClassConstFetchRuntime::fetchDynamicByClassIdValue(
            $objectType,
            $classIdVal,
            $nameVar,
            $classOp,
            $block,
            $jit
        );
    }

    private static function classPseudoConst(Object_ $objectType, int $classId): Variable
    {
        $context = $objectType->jitContext();
        $lit = new Operand\Literal($objectType->classNameForId($classId));
        $lit->type = \PHPTypes\Type::string();

        return Variable::fromLiteral($context, $lit);
    }

    public static function resolveJitScopeClassNameForBlock(Object_ $objectType, Block $block): ?string
    {
        return self::jitScopeClassName($objectType, $block);
    }

    /**
     * @return Value {@see __string__*}
     */
    public static function emitClassNameStringFromClassId(Object_ $objectType, Value $classId): Value
    {
        return self::classNameStringFromId($objectType, $classId);
    }

    private static function resolveJitClassNameString(Object_ $objectType, Block $block, string $className): string
    {
        $lc = strtolower($className);
        if ('self' === $lc) {
            $scope = self::jitScopeClassName($objectType, $block);
            if (null === $scope) {
                PseudoClassScope::fatalInGlobalScope('self');
            }

            return $scope;
        }
        if ('static' === $lc) {
            $scope = self::jitLateStaticClassName($objectType, $block);
            if (null === $scope) {
                PseudoClassScope::fatalInGlobalScope('static');
            }

            return $scope;
        }
        if ('parent' === $lc) {
            $scope = self::jitScopeClassName($objectType, $block);
            if (null === $scope) {
                PseudoClassScope::fatalInGlobalScope('parent');
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

    public static function jitLateStaticClassNameForBlock(Object_ $objectType, Block $block): ?string
    {
        return self::jitLateStaticClassName($objectType, $block);
    }

    private static function jitLateStaticClassName(Object_ $objectType, Block $block): ?string
    {
        $called = $objectType->jitContext()->scope->calledClassName ?? '';
        $declaring = self::jitScopeClassName($objectType, $block);
        if (null === $declaring) {
            return '' !== $called ? $called : null;
        }

        return \PHPCompiler\VM\LateStaticBinding::resolveLateStaticClassLc(
            '' !== $called ? $called : null,
            $declaring
        );
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
     * @return Value int64 class id
     */
    private static function emitResolveClassIdFromNameString(Object_ $objectType, Value $resolvedNameStr): Value
    {
        $context = $objectType->jitContext();
        self::ensureStrCaseCmp($context);
        ErrorRaise::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sentinel = $i64->constInt(-1, false);
        $result = $sentinel;
        foreach ($objectType->allClassNamesById() as $id => $canonical) {
            $lcGlobal = $context->builder->load(
                $context->constantStringFromString(strtolower(ltrim($canonical, '\\')))
            );
            $cmp = $context->builder->call(
                $context->lookupFunction('strcasecmp'),
                self::stringDataPtr($context, $resolvedNameStr),
                self::stringDataPtr($context, $lcGlobal)
            );
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $idVal = $context->constantFromInteger($id, 'int64');
            $result = $context->builder->select($isMatch, $idVal, $result);
        }

        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $ok = $fn->appendBasicBlock('class_const_resolve_id_ok');
        $fail = $fn->appendBasicBlock('class_const_resolve_id_fail');
        $merge = $fn->appendBasicBlock('class_const_resolve_id_merge');
        $isUnknown = $context->builder->icmp(Builder::INT_EQ, $result, $sentinel);
        $context->builder->branchIf($isUnknown, $fail, $ok);

        $context->builder->positionAtEnd($fail);
        ErrorRaise::emitRaise($context, 'Class not found');
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($ok);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

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
            self::messageDataPtrForRuntime($context, $message),
            $context->constantFromInteger(strlen($message), 'size_t')
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($ok);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return $result;
    }

    public static function writeEnumCaseConstEntryForRuntime(
        Object_ $objectType,
        Context $context,
        Value $slot,
        int $classId,
        string $caseKey
    ): void {
        $globalName = $objectType->ensureEnumCaseSingletonGlobal($classId, $caseKey);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException("Missing enum case singleton global: {$globalName}");
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );
        $obj = $context->builder->load($global);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );
    }

    /**
     * @param array{type: int, value: int|float|bool|string|null} $entry
     */
    public static function writeConstEntryForRuntime(Context $context, Value $slot, array $entry): void
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
            case Variable::TYPE_HASHTABLE:
                if (isset($entry['global'])) {
                    $global = $context->module->getNamedGlobal($entry['global']);
                    if (null === $global) {
                        throw new \LogicException("Missing class constant hashtable global: {$entry['global']}");
                    }
                    $htPtr = $context->builder->load($global);
                    $context->refcount->addref($htPtr);
                    $context->builder->call(
                        $context->lookupFunction('__value__writeHashtable'),
                        JitValueBox::pointer($context, $slot),
                        $htPtr
                    );
                    break;
                }
                if (!isset($entry['vmTable']) || !$entry['vmTable'] instanceof \PHPCompiler\VM\HashTable) {
                    throw new \LogicException('Missing VM table for class constant array');
                }
                $htVar = HashTableHelper::variableFromVmHashTable($context, $entry['vmTable']);
                $htPtr = $context->helper->loadValue($htVar);
                $context->refcount->addref($htPtr);
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    JitValueBox::pointer($context, $slot),
                    $htPtr
                );
                break;
            case Variable::TYPE_OBJECT:
                if (!isset($entry['global'])) {
                    throw new \LogicException('Missing global for class constant object');
                }
                $global = $context->module->getNamedGlobal($entry['global']);
                if (null === $global) {
                    throw new \LogicException("Missing class constant object global: {$entry['global']}");
                }
                // Immortal module-global object: clear slot before writeObject (#3196, #4028).
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $slot)
                );
                $obj = $context->builder->load($global);
                $context->builder->call(
                    $context->lookupFunction('__value__writeObject'),
                    JitValueBox::pointer($context, $slot),
                    $obj
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
        if ($classId < 0) {
            return '*';
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

    public static function messageDataPtrForRuntime(Context $context, string $message): Value
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
