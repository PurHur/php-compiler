<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\DnfType;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectType;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Builder;

/**
 * DNF type checks at JIT/AOT call sites, returns, and property writes (#3094, #4008).
 *
 * @phpstan-import-type DnfArm from \PHPCompiler\DnfType
 */
final class DnfParamCheck
{
    /** Optional Zend-shaped Argument TypeError context (#29859). */
    private static ?string $paramFunctionName = null;

    private static ?int $paramIndex = null;

    private static ?string $paramName = null;

    /**
     * @param list<DnfArm> $arms
     */
    public static function enforcePropertyWrite(Context $context, Variable $value, array $arms): void
    {
        self::enforce($context, $value, $arms, 'Property');
    }

    /**
     * @param list<DnfArm> $arms
     */
    public static function enforce(
        Context $context,
        Variable $arg,
        array $arms,
        string $kind = 'Argument',
        ?string $functionName = null,
        ?int $paramIndex = null,
        ?string $paramName = null
    ): void {
        if ([] === $arms) {
            return;
        }
        if (self::compileTimeArmKnown($arg, $arms)) {
            return;
        }
        if (1 === \count($arms) && 'intersection' === $arms[0]['kind']) {
            IntersectionParamCheck::enforce($context, $arg, $arms[0]['interfaces'], $kind);

            return;
        }
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $expected = DnfType::formatUnionType($arms);
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        $pass = $fn->appendBasicBlock('dnf_pass');
        $fail = $fn->appendBasicBlock('dnf_fail');
        $resume = $fn->appendBasicBlock('dnf_resume');
        $check = $entry;
        foreach ($arms as $i => $arm) {
            $match = $fn->appendBasicBlock('dnf_arm_ok_'.$i);
            $next = $fn->appendBasicBlock('dnf_arm_next_'.$i);
            $context->builder->positionAtEnd($check);
            $ok = self::emitArmMatches($context, $arg, $arm);
            $bool = $context->helper->loadValue($ok);
            $context->builder->branchIf($bool, $match, $next);
            $context->builder->positionAtEnd($match);
            $context->builder->branch($pass);
            $check = $next;
        }
        $context->builder->positionAtEnd($check);
        $context->builder->branch($fail);
        $context->builder->positionAtEnd($fail);
        $prevFn = self::$paramFunctionName;
        $prevIdx = self::$paramIndex;
        $prevName = self::$paramName;
        self::$paramFunctionName = $functionName;
        self::$paramIndex = $paramIndex;
        self::$paramName = $paramName;
        try {
            self::raiseTypeErrorForValue($context, $arg, $kind, $expected);
        } finally {
            self::$paramFunctionName = $prevFn;
            self::$paramIndex = $prevIdx;
            self::$paramName = $prevName;
        }
        $context->builder->positionAtEnd($pass);
        $context->builder->branch($resume);
        $context->builder->positionAtEnd($resume);
    }

    /**
     * @param list<DnfArm> $arms
     */
    private static function compileTimeArmKnown(Variable $arg, array $arms): bool
    {
        foreach ($arms as $arm) {
            if (!self::compileTimeMatchesArm($arg, $arm)) {
                continue;
            }

            return true;
        }
        if (self::armsIncludeNull($arms)
            && (Variable::TYPE_NULL === $arg->type
                || (Variable::TYPE_VALUE === $arg->type && ($arg->isNullConstant ?? false)))) {
            return true;
        }

        return false;
    }

    /**
     * @param list<DnfArm> $arms
     */
    private static function armsIncludeNull(array $arms): bool
    {
        foreach ($arms as $arm) {
            if ('null' === $arm['kind']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param DnfArm $arm
     */
    private static function compileTimeMatchesArm(Variable $arg, array $arm): bool
    {
        return match ($arm['kind']) {
            'null' => Variable::TYPE_NULL === $arg->type
                || (Variable::TYPE_VALUE === $arg->type && ($arg->isNullConstant ?? false)),
            'literal' => self::compileTimeMatchesLiteralArm($arg, $arm['name']),
            default => false,
        };
    }

    /**
     * @param DnfArm $arm
     */
    private static function emitArmMatches(Context $context, Variable $arg, array $arm): Variable
    {
        return match ($arm['kind']) {
            'null' => self::emitIsNull($context, $arg),
            'literal' => self::emitLiteralMatches($context, $arg, $arm['name']),
            'intersection' => self::emitIntersectionMatches($context, $arg, $arm['interfaces']),
            default => new Variable(
                $context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $context->getTypeFromString('int1')->constInt(0, false)
            ),
        };
    }

    private static function emitIsNull(Context $context, Variable $arg): Variable
    {
        $i1 = $context->getTypeFromString('int1');
        if (Variable::TYPE_NULL === $arg->type) {
            return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $i1->constInt(1, false));
        }
        $scalar = self::scalarGivenLabel($arg);
        if (null !== $scalar) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $i1->constInt('null' === $scalar ? 1 : 0, false)
            );
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::emitValueBoxTypeEquals($context, $arg, \PHPCompiler\VM\Variable::TYPE_NULL);
        }

        return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $i1->constInt(0, false));
    }

    private static function compileTimeMatchesLiteralArm(Variable $arg, string $name): bool
    {
        if ('true' === $name || 'false' === $name) {
            if (Variable::TYPE_NATIVE_BOOL !== $arg->type) {
                return false;
            }
            if ($arg->value instanceof \PHPLLVM\Value) {
                return false;
            }
            $isTrue = 0 !== (int) $arg->value;

            return ('true' === $name) === $isTrue;
        }
        // Closures / FCC are compile-time callables when the receiver resolves (#25561).
        // Full zend_is_callable for strings/arrays stays on the runtime emit path.
        if ('callable' === $name) {
            return false;
        }
        if ('iterable' === $name) {
            return Variable::TYPE_HASHTABLE === $arg->type
                || (bool) ($arg->type & Variable::IS_NATIVE_ARRAY);
        }

        return self::scalarGivenLabel($arg) === $name;
    }

    private static function emitLiteralMatches(Context $context, Variable $arg, string $name): Variable
    {
        if ('true' === $name || 'false' === $name) {
            return self::emitLiteralTrueFalseMatches($context, $arg, 'true' === $name);
        }
        if ('callable' === $name) {
            return self::emitCallableMatches($context, $arg);
        }
        if ('iterable' === $name) {
            return self::emitIterableMatches($context, $arg);
        }
        $i1 = $context->getTypeFromString('int1');
        $vmTy = match ($name) {
            'int' => \PHPCompiler\VM\Variable::TYPE_INTEGER,
            'float' => \PHPCompiler\VM\Variable::TYPE_FLOAT,
            'bool' => \PHPCompiler\VM\Variable::TYPE_BOOLEAN,
            'string' => \PHPCompiler\VM\Variable::TYPE_STRING,
            'array' => \PHPCompiler\VM\Variable::TYPE_ARRAY,
            'object' => \PHPCompiler\VM\Variable::TYPE_OBJECT,
            'null' => \PHPCompiler\VM\Variable::TYPE_NULL,
            default => null,
        };
        if (null !== $vmTy) {
            $scalar = self::scalarGivenLabel($arg);
            if (null !== $scalar) {
                return new Variable(
                    $context,
                    Variable::TYPE_NATIVE_BOOL,
                    Variable::KIND_VALUE,
                    $i1->constInt($scalar === $name ? 1 : 0, false)
                );
            }
            if (Variable::TYPE_VALUE === $arg->type) {
                return self::emitValueBoxTypeEquals($context, $arg, $vmTy);
            }

            return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $i1->constInt(0, false));
        }

        return self::emitClassNameLiteralMatches($context, $arg, $name);
    }

    /**
     * Union arm `callable` — Closures / FCC / __invoke objects (#25561).
     * String/array callables still need a full zend_is_callable probe; when a sibling
     * `string`/`array` arm is present they match that arm instead.
     */
    private static function emitCallableMatches(Context $context, Variable $arg): Variable
    {
        $i1 = $context->getTypeFromString('int1');
        if (null !== ClosureHelper::resolveCall($context, $arg)) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $i1->constInt(1, false)
            );
        }
        $scalar = self::scalarGivenLabel($arg);
        if (null !== $scalar) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $i1->constInt(0, false)
            );
        }
        if (
            Variable::TYPE_OBJECT !== $arg->type
            && Variable::TYPE_VALUE !== $arg->type
        ) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $i1->constInt(0, false)
            );
        }
        $isClosure = self::emitClassNameLiteralMatches($context, $arg, 'closure');
        $objectType = $context->type->object;
        if (!$objectType instanceof ObjectType) {
            return $isClosure;
        }
        $hasInvoke = self::emitObjectHasInvoke($context, $objectType, $arg);

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $context->builder->or(
                $context->helper->loadValue($isClosure),
                $context->helper->loadValue($hasInvoke)
            )
        );
    }

    private static function emitObjectHasInvoke(
        Context $context,
        ObjectType $objectType,
        Variable $arg
    ): Variable {
        $i1 = $context->getTypeFromString('int1');
        $acc = $i1->constInt(0, false);
        $obj = self::objectPointer($context, $arg);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        foreach ($objectType->allClassNamesById() as $id => $name) {
            if (!$objectType->hasMethod($id, '__invoke')) {
                continue;
            }
            $expected = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $acc = $context->builder->or($acc, $isId);
        }

        return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $acc);
    }

    /** Union arm `iterable` — array or Traversable (#25561 / zend_type.c IS_ITERABLE). */
    private static function emitIterableMatches(Context $context, Variable $arg): Variable
    {
        $i1 = $context->getTypeFromString('int1');
        $scalar = self::scalarGivenLabel($arg);
        if ('array' === $scalar) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $i1->constInt(1, false)
            );
        }
        if (null !== $scalar) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $i1->constInt(0, false)
            );
        }
        $isArray = Variable::TYPE_VALUE === $arg->type
            ? self::emitValueBoxTypeEquals($context, $arg, \PHPCompiler\VM\Variable::TYPE_ARRAY)
            : new Variable(
                $context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $i1->constInt(
                    Variable::TYPE_HASHTABLE === $arg->type
                        || (bool) ($arg->type & Variable::IS_NATIVE_ARRAY)
                        ? 1
                        : 0,
                    false
                )
            );
        $isTraversable = self::emitClassNameLiteralMatches($context, $arg, 'traversable');

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $context->builder->or(
                $context->helper->loadValue($isArray),
                $context->helper->loadValue($isTraversable)
            )
        );
    }

    private static function emitClassNameLiteralMatches(Context $context, Variable $arg, string $name): Variable
    {
        $i1 = $context->getTypeFromString('int1');
        $objectType = $context->type->object;
        if (!$objectType instanceof ObjectType) {
            return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $i1->constInt(0, false));
        }
        if (null !== self::scalarGivenLabel($arg)) {
            return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $i1->constInt(0, false));
        }

        return $objectType->emitInstanceOf($arg, $name);
    }

    private static function emitLiteralTrueFalseMatches(Context $context, Variable $arg, bool $expectTrue): Variable
    {
        $i1 = $context->getTypeFromString('int1');
        $expected = $i1->constInt($expectTrue ? 1 : 0, false);
        if (Variable::TYPE_NATIVE_BOOL === $arg->type) {
            $ok = $context->builder->icmp(Builder::INT_EQ, $arg->value, $expected);

            return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $ok);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            $isBool = self::emitValueBoxTypeEquals($context, $arg, \PHPCompiler\VM\Variable::TYPE_BOOLEAN);
            $valueOk = self::emitValueBoxBoolEquals($context, $arg, $expectTrue);
            $ok = $context->builder->and(
                $context->helper->loadValue($isBool),
                $context->helper->loadValue($valueOk)
            );

            return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $ok);
        }

        return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $i1->constInt(0, false));
    }

    private static function emitValueBoxBoolEquals(Context $context, Variable $arg, bool $expectTrue): Variable
    {
        $i1 = $context->getTypeFromString('int1');
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $valueField = $context->builder->structGep($valuePtr, $map['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $loaded = $context->builder->load($firstByte);
        $ok = $context->builder->icmp(
            Builder::INT_EQ,
            $loaded,
            $i8->constInt($expectTrue ? 1 : 0, false)
        );

        return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $ok);
    }

    /**
     * @param list<string> $interfaceLcs
     */
    private static function emitIntersectionMatches(Context $context, Variable $arg, array $interfaceLcs): Variable
    {
        $i1 = $context->getTypeFromString('int1');
        if ([] === $interfaceLcs) {
            return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $i1->constInt(1, false));
        }
        $objectType = $context->type->object;
        assert($objectType instanceof ObjectType);
        if (Variable::TYPE_VALUE === $arg->type) {
            $isObject = self::emitValueBoxTypeEquals($context, $arg, \PHPCompiler\VM\Variable::TYPE_OBJECT);
            $fn = $context->builder->getInsertBlock()->getParent();
            assert($fn instanceof \PHPLLVM\Value\Function_);
            $entry = $context->builder->getInsertBlock();
            $ok = $fn->appendBasicBlock('dnf_isect_value_object');
            $fail = $fn->appendBasicBlock('dnf_isect_value_not_object');
            $resume = $fn->appendBasicBlock('dnf_isect_value_done');
            $bool = $context->helper->loadValue($isObject);
            $context->builder->branchIf($bool, $ok, $fail);
            $context->builder->positionAtEnd($fail);
            $falseVal = $i1->constInt(0, false);
            $context->builder->branch($resume);
            $context->builder->positionAtEnd($ok);
            $acc = $i1->constInt(1, false);
            foreach ($interfaceLcs as $memberLc) {
                $okIface = self::emitMemberSatisfies($context, $objectType, $arg, $memberLc);
                $acc = $context->builder->and($acc, $context->helper->loadValue($okIface));
            }
            $context->builder->branch($resume);
            $context->builder->positionAtEnd($resume);
            $phi = $context->builder->phi($i1);
            $phi->addIncoming($falseVal, $fail);
            $phi->addIncoming($acc, $ok);

            return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $phi);
        }
        $scalar = self::scalarGivenLabel($arg);
        if (null !== $scalar) {
            return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $i1->constInt(0, false));
        }
        $acc = $i1->constInt(1, false);
        foreach ($interfaceLcs as $memberLc) {
            $ok = self::emitMemberSatisfies($context, $objectType, $arg, $memberLc);
            $acc = $context->builder->and($acc, $context->helper->loadValue($ok));
        }

        return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $acc);
    }

    private static function emitValueBoxTypeEquals(Context $context, Variable $arg, int $vmTy): Variable
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        // Value-box writers store JIT tags (TYPE_HASHTABLE=135, TYPE_OBJECT=133, …).
        // Comparing raw VM TYPE_ARRAY (6) missed arrays and fell through to
        // emitObjectFailureMessage → __value__readObject(null) → AOT segfault (#27624).
        // Mask IS_REFCOUNTED like __value__readHashtable (#26977).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $jitTy = Variable::jitTypeByteFromVmType($vmTy);
        $isTy = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt($jitTy & 0x7f, false)
        );
        // Some slots still carry bare VM TYPE_ARRAY (6); accept that for array arms too
        // (peer ArrayColumnLlvm — #27624 / #26977).
        if (\PHPCompiler\VM\Variable::TYPE_ARRAY === $vmTy) {
            $isVmArray = $context->builder->icmp(
                Builder::INT_EQ,
                $kind,
                $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ARRAY, false)
            );
            $isTy = $context->builder->or($isTy, $isVmArray);
        }

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $isTy
        );
    }

    private static function emitMemberSatisfies(
        Context $context,
        ObjectType $objectType,
        Variable $arg,
        string $memberLc
    ): Variable {
        $memberLc = strtolower(ltrim($memberLc, '\\'));
        if ($objectType->isInterfaceClassLc($memberLc)) {
            return self::emitImplements($context, $objectType, $arg, $memberLc);
        }

        return $objectType->emitInstanceOf($arg, $memberLc);
    }

    private static function emitImplements(
        Context $context,
        ObjectType $objectType,
        Variable $arg,
        string $ifaceLc
    ): Variable {
        $ifaceLc = strtolower(ltrim($ifaceLc, '\\'));
        $obj = self::objectPointer($context, $arg);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );

        return self::emitClassIdImplements($context, $objectType, $classId, $ifaceLc);
    }

    private static function emitClassIdImplements(
        Context $context,
        ObjectType $objectType,
        \PHPLLVM\Value $classId,
        string $ifaceLc
    ): Variable {
        $i1 = $context->getTypeFromString('int1');
        $acc = $i1->constInt(0, false);
        foreach ($objectType->allClassNamesById() as $id => $name) {
            $classLc = strtolower(ltrim($name, '\\'));
            $ifaces = $objectType->allInterfacesForClassLc($classLc);
            $matches = in_array($ifaceLc, $ifaces, true)
                || ($objectType->isInterfaceClassLc($classLc) && $classLc === $ifaceLc)
                || ('stringable' === $ifaceLc && $objectType->classHasImplicitStringableLc($classLc));
            if (!$matches) {
                continue;
            }
            $expected = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $acc = $context->builder->or($acc, $isId);
        }

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $acc
        );
    }

    private static function raiseTypeErrorForValue(
        Context $context,
        Variable $arg,
        string $kind,
        string $expected
    ): void {
        $scalarGiven = self::scalarGivenLabel($arg);
        if (null !== $scalarGiven) {
            self::raiseTypeErrorAndAbort(
                $context,
                self::formatTypeErrorMessage($context, $kind, $expected, $scalarGiven)
            );

            return;
        }
        $objectType = $context->type->object;
        assert($objectType instanceof ObjectType);
        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitObjectFailureMessage($context, $objectType, $arg, $kind, $expected);

            return;
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            // Do not assume TYPE_VALUE is an object — arrays/scalars use the same box
            // and __value__readObject returns null → segfault (#27624).
            self::emitValueBoxFailureMessage($context, $objectType, $arg, $kind, $expected);

            return;
        }
        self::raiseTypeErrorAndAbort(
            $context,
            self::formatTypeErrorMessage($context, $kind, $expected, 'mixed')
        );
    }

    /**
     * Zend-shaped Argument TypeError when call-site context is available (#29859);
     * otherwise keep the short `{kind} must be of type …` form.
     */
    private static function formatTypeErrorMessage(
        Context $context,
        string $kind,
        string $expected,
        string $given
    ): string {
        if (
            'Argument' === $kind
            && null !== self::$paramFunctionName
            && null !== self::$paramIndex
            && null !== self::$paramName
        ) {
            $message = sprintf(
                '%s(): Argument #%d ($%s) must be of type %s, %s given',
                \PHPCompiler\VM\ParamArgumentCountError::formatUserFunctionName(self::$paramFunctionName),
                self::$paramIndex + 1,
                self::$paramName,
                $expected,
                $given
            );
            $path = $context->jitAotEntryScriptPath;
            if ($context->callSiteLine > 0 && '' !== $path) {
                $message .= sprintf(', called in %s on line %d', $path, $context->callSiteLine);
            }

            return $message;
        }

        // zend_verify_return_type — "… returned" + optional fn(): prefix (#29887).
        if ('Return value' === $kind) {
            $message = sprintf(
                'Return value must be of type %s, %s returned',
                $expected,
                $given
            );
            if (null !== self::$paramFunctionName && '' !== self::$paramFunctionName) {
                $message = \PHPCompiler\VM\ParamArgumentCountError::formatUserFunctionName(
                    self::$paramFunctionName
                ).'(): '.$message;
            }

            return $message;
        }

        return sprintf('%s must be of type %s, %s given', $kind, $expected, $given);
    }

    private static function emitValueBoxFailureMessage(
        Context $context,
        ObjectType $objectType,
        Variable $arg,
        string $kind,
        string $expected
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kindByte = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $check = $context->builder->getInsertBlock();
        $labels = [
            ['int', Variable::TYPE_NATIVE_LONG],
            ['float', Variable::TYPE_NATIVE_DOUBLE],
            ['bool', Variable::TYPE_NATIVE_BOOL],
            ['string', Variable::TYPE_STRING & 0x7f],
            ['null', Variable::TYPE_NULL],
            ['array', Variable::TYPE_HASHTABLE & 0x7f],
            ['array', \PHPCompiler\VM\Variable::TYPE_ARRAY],
        ];
        foreach ($labels as [$label, $ty]) {
            $match = $fn->appendBasicBlock('dnf_fail_value_'.$label.'_'.$ty);
            $next = $fn->appendBasicBlock('dnf_fail_value_try_'.$label.'_'.$ty);
            $context->builder->positionAtEnd($check);
            $isTy = $context->builder->icmp(
                Builder::INT_EQ,
                $kindByte,
                $i8->constInt($ty, false)
            );
            $context->builder->branchIf($isTy, $match, $next);
            $context->builder->positionAtEnd($match);
            self::raiseTypeErrorAndAbort(
                $context,
                self::formatTypeErrorMessage($context, $kind, $expected, $label)
            );
            $check = $next;
        }
        $objectOk = $fn->appendBasicBlock('dnf_fail_value_as_object');
        $notObject = $fn->appendBasicBlock('dnf_fail_value_mixed');
        $context->builder->positionAtEnd($check);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kindByte,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        $context->builder->branchIf($isObject, $objectOk, $notObject);
        $context->builder->positionAtEnd($objectOk);
        self::emitObjectFailureMessage($context, $objectType, $arg, $kind, $expected);
        $context->builder->positionAtEnd($notObject);
        self::raiseTypeErrorAndAbort(
            $context,
            self::formatTypeErrorMessage($context, $kind, $expected, 'mixed')
        );
    }

    private static function raiseTypeErrorAndAbort(Context $context, string $message): void
    {
        // Catchable in try/catch; uncaught AOT prints Fatal TypeError and exit(255)
        // via phpc_jit_abort_if_pending_type_error — not libc abort/SIGABRT (#29859).
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
    }

    private static function emitObjectFailureMessage(
        Context $context,
        ObjectType $objectType,
        Variable $arg,
        string $kind,
        string $expected
    ): void {
        $obj = self::objectPointer($context, $arg);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        $defaultBlock = $fn->appendBasicBlock('dnf_fail_default');
        $checkBlock = $entry;
        foreach ($objectType->allClassNamesById() as $id => $name) {
            // Preserve case; strip @anonymous\0file:line$id like zend %s (#29569 / #26031).
            $given = \PHPCompiler\MethodVisibility::formatAnonymousScopeForMessage(
                ltrim($name, '\\')
            );
            $message = self::formatTypeErrorMessage($context, $kind, $expected, $given);
            $matchBlock = $fn->appendBasicBlock('dnf_fail_msg_'.$id);
            $nextBlock = $fn->appendBasicBlock('dnf_fail_try_'.$id);
            $context->builder->positionAtEnd($checkBlock);
            $expectedId = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expectedId);
            $context->builder->branchIf($isId, $matchBlock, $nextBlock);
            $context->builder->positionAtEnd($matchBlock);
            self::raiseTypeErrorAndAbort($context, $message);
            $checkBlock = $nextBlock;
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($defaultBlock);
        $context->builder->positionAtEnd($defaultBlock);
        self::raiseTypeErrorAndAbort(
            $context,
            self::formatTypeErrorMessage($context, $kind, $expected, 'object')
        );
    }

    private static function scalarGivenLabel(Variable $arg): ?string
    {
        return match ($arg->type) {
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_NATIVE_BOOL => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_HASHTABLE => 'array',
            default => null,
        };
    }

    private static function objectPointer(Context $context, Variable $arg): \PHPLLVM\Value
    {
        if (Variable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $valuePtr
            );
        }

        return $context->getTypeFromString('__object__*')->constNull();
    }
}
