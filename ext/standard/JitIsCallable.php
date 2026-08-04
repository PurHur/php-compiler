<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\ClosureHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\VariableFunctionCallHelper;
use PHPCompiler\MethodVisibility;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for is_callable() (issue #3132, array callables #27173).
 *
 * Array [object, method] / [Class, static] use pure LLVM class_id + method-name
 * compares — NestedJIT VmReflection::methodExists is false for DateTime under
 * thin AOT (same gap as method_exists(DateTime) AOT on this tree).
 */
final class JitIsCallable
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            \PHPCompiler\JIT\ExceptionBridge::emitArgumentCountError(
                $context,
                \sprintf('is_callable() expects at least 1 argument, %d given', $argc)
            );

            return $context->constantFromInteger(0, 'int1');
        }
        if ($argc > 3) {
            \PHPCompiler\JIT\ExceptionBridge::emitArgumentCountError(
                $context,
                \sprintf('is_callable() expects at most 3 arguments, %d given', $argc)
            );

            return $context->constantFromInteger(0, 'int1');
        }
        $callback = $args[0];
        $syntaxOnly = false;
        $nameOut = $args[2] ?? null;

        if (null !== ClosureHelper::resolveCall($context, $callback)) {
            if (null !== $nameOut) {
                self::jitWriteCallableNameLiteral($context, '{closure}', $nameOut);
            }

            return $context->constantFromInteger(1, 'int1');
        }

        $literal = JitStringArg::compileTimeLiteral($callback);
        if (null !== $literal) {
            if (null !== $nameOut) {
                self::jitWriteCallableNameLiteral($context, $literal, $nameOut);
            }

            return self::checkCompileTimeString($context, $literal, $syntaxOnly);
        }

        if (
            JITVariable::TYPE_VALUE === $callback->type
            || JITVariable::TYPE_HASHTABLE === $callback->type
        ) {
            return self::checkValueOrHashtable($context, $callback, $nameOut);
        }

        if (JITVariable::TYPE_STRING === $callback->type) {
            if (null !== $nameOut) {
                self::jitWriteCallableNameFromVariable($context, $callback, $nameOut);
            }

            return self::checkRuntimeString($context, $callback);
        }

        if (JITVariable::TYPE_OBJECT === $callback->type) {
            $candidates = ClosureHelper::closureCandidates($context);
            if ([] !== $candidates) {
                if (null !== $nameOut) {
                    self::jitWriteCallableNameLiteral($context, '{closure}', $nameOut);
                }

                return $context->constantFromInteger(1, 'int1');
            }
        }

        if (null !== $nameOut && JITVariable::TYPE_NULL === $callback->type) {
            self::jitWriteCallableNameLiteral($context, '', $nameOut);
        }

        return $context->constantFromInteger(0, 'int1');
    }

    private static function checkValueOrHashtable(
        Context $context,
        JITVariable $callback,
        ?JITVariable $nameOut
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $i8 = $context->getTypeFromString('int8');

        if (JITVariable::TYPE_HASHTABLE === $callback->type) {
            $ht = $context->helper->loadValue($callback);

            return self::checkArrayHashtable($context, $ht, $nameOut);
        }

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $callback);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $typeField)
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_STRING & 0x7f, false)
        );
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_ARRAY & 0x7f, false)
        );
        $isHtTag = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_HASHTABLE & 0x7f, false)
        );
        $isHashtable = $context->builder->or($isArray, $isHtTag);

        $stringBb = BasicBlockHelper::append($context, 'is_callable_vb_str');
        $notString = BasicBlockHelper::append($context, 'is_callable_vb_not_str');
        $arrayBb = BasicBlockHelper::append($context, 'is_callable_vb_arr');
        $falseBb = BasicBlockHelper::append($context, 'is_callable_vb_false');
        $merge = BasicBlockHelper::append($context, 'is_callable_vb_merge');

        $context->builder->branchIf($isString, $stringBb, $notString);

        $context->builder->positionAtEnd($stringBb);
        if (null !== $nameOut) {
            self::jitWriteCallableNameFromVariable($context, $callback, $nameOut);
        }
        $strResult = self::checkRuntimeString($context, $callback);
        $strEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($notString);
        $context->builder->branchIf($isHashtable, $arrayBb, $falseBb);

        $context->builder->positionAtEnd($arrayBb);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        $arrResult = self::checkArrayHashtable($context, $ht, $nameOut);
        $arrEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($falseBb);
        $falseResult = $i1->constInt(0, false);
        if (null !== $nameOut) {
            self::jitWriteCallableNameLiteral($context, '', $nameOut);
        }
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($strResult, $strEnd);
        $phi->addIncoming($arrResult, $arrEnd);
        $phi->addIncoming($falseResult, $falseBb);

        return $phi;
    }

    private static function checkArrayHashtable(
        Context $context,
        Value $ht,
        ?JITVariable $nameOut
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');

        $has0 = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $sizeT->constInt(0, false)
        );
        $has1 = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $sizeT->constInt(1, false)
        );
        $hasBoth = $context->builder->and($has0, $has1);

        $okBb = BasicBlockHelper::append($context, 'is_callable_arr_ok');
        $badBb = BasicBlockHelper::append($context, 'is_callable_arr_bad');
        $merge = BasicBlockHelper::append($context, 'is_callable_arr_merge');
        $context->builder->branchIf($hasBoth, $okBb, $badBb);

        $context->builder->positionAtEnd($badBb);
        $badResult = $i1->constInt(0, false);
        if (null !== $nameOut) {
            self::jitWriteCallableNameLiteral($context, 'Array', $nameOut);
        }
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($okBb);
        $elem0 = HashTableReadLlvm::readIndexedToValueBox(
            $context,
            $ht,
            $sizeT->constInt(0, false)
        );
        $elem1 = HashTableReadLlvm::readIndexedToValueBox(
            $context,
            $ht,
            $sizeT->constInt(1, false)
        );
        $okResult = self::checkArrayElements($context, $elem0, $elem1, $nameOut);
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($okResult, $okEnd);
        $phi->addIncoming($badResult, $badBb);

        return $phi;
    }

    private static function checkArrayElements(
        Context $context,
        JITVariable $elem0,
        JITVariable $elem1,
        ?JITVariable $nameOut
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $i8 = $context->getTypeFromString('int8');

        $ptr0 = JitValueBox::valuePtrFromVariable($context, $elem0);
        $ptr1 = JitValueBox::valuePtrFromVariable($context, $elem1);
        $typeField = $context->structFieldMap['__value__']['type'];
        $kind0 = $context->builder->and(
            $context->builder->load($context->builder->structGep($ptr0, $typeField)),
            $i8->constInt(0x7f, false)
        );
        $kind1 = $context->builder->and(
            $context->builder->load($context->builder->structGep($ptr1, $typeField)),
            $i8->constInt(0x7f, false)
        );
        $methodIsString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind1,
            $i8->constInt(JITVariable::TYPE_STRING & 0x7f, false)
        );
        $targetIsObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind0,
            $i8->constInt(JITVariable::TYPE_OBJECT & 0x7f, false)
        );
        $targetIsString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind0,
            $i8->constInt(JITVariable::TYPE_STRING & 0x7f, false)
        );

        $methodOk = BasicBlockHelper::append($context, 'is_callable_el_method');
        $methodBad = BasicBlockHelper::append($context, 'is_callable_el_method_bad');
        $objBb = BasicBlockHelper::append($context, 'is_callable_el_obj');
        $notObj = BasicBlockHelper::append($context, 'is_callable_el_not_obj');
        $strBb = BasicBlockHelper::append($context, 'is_callable_el_str');
        $falseBb = BasicBlockHelper::append($context, 'is_callable_el_false');
        $merge = BasicBlockHelper::append($context, 'is_callable_el_merge');

        $context->builder->branchIf($methodIsString, $methodOk, $methodBad);

        $context->builder->positionAtEnd($methodBad);
        $badMethod = $i1->constInt(0, false);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($methodOk);
        $methodStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $ptr1
        );
        $context->builder->branchIf($targetIsObject, $objBb, $notObj);

        $context->builder->positionAtEnd($objBb);
        if (null !== $nameOut) {
            self::jitWriteCallableNameLiteral($context, '', $nameOut);
        }
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $ptr0
        );
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $objResult = self::llvmObjectMethodCallable($context, $classId, $methodStr);
        $objEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($notObj);
        $context->builder->branchIf($targetIsString, $strBb, $falseBb);

        $context->builder->positionAtEnd($strBb);
        $classStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $ptr0
        );
        if (null !== $nameOut) {
            self::jitWriteCallableNameLiteral($context, '', $nameOut);
        }
        $strResult = self::llvmStaticMethodCallable($context, $classStr, $methodStr);
        $strEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($falseBb);
        $falseResult = $i1->constInt(0, false);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($badMethod, $methodBad);
        $phi->addIncoming($objResult, $objEnd);
        $phi->addIncoming($strResult, $strEnd);
        $phi->addIncoming($falseResult, $falseBb);

        return $phi;
    }

    /**
     * Public instance methods visible with no caller scope (zend_is_callable outside class).
     */
    private static function llvmObjectMethodCallable(
        Context $context,
        Value $classId,
        Value $methodStr
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $object = $context->type->object;
        $methodData = self::stringDataPtr($context, $methodStr);
        $strcasecmp = $context->lookupFunction('strcasecmp');
        $exists = $i1->constInt(0, false);
        foreach ($object->allClassNamesById() as $id => $className) {
            $isClass = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $hit = $i1->constInt(0, false);
            $classLc = strtolower(ltrim($className, '\\'));
            foreach (self::callableMethodNamesForClass($context, $object, $id, $classLc) as $methodLc) {
                $lit = $context->builder->load($context->constantStringFromString($methodLc));
                $litData = self::stringDataPtr($context, $lit);
                $cmp = $context->builder->call($strcasecmp, $methodData, $litData);
                $match = $context->builder->icmp(
                    Builder::INT_EQ,
                    $cmp,
                    $i32->constInt(0, false)
                );
                $hit = $context->builder->or($hit, $match);
            }
            $exists = $context->builder->select($isClass, $hit, $exists);
        }

        return $exists;
    }

    /**
     * Class-string callables — public methods on the named class (#27173).
     */
    private static function llvmStaticMethodCallable(
        Context $context,
        Value $classStr,
        Value $methodStr
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $object = $context->type->object;
        $strcasecmp = $context->lookupFunction('strcasecmp');
        $classData = self::stringDataPtr($context, $classStr);
        $methodData = self::stringDataPtr($context, $methodStr);
        $exists = $i1->constInt(0, false);
        foreach ($object->allClassNamesById() as $id => $className) {
            $names = [$className];
            if (str_contains($className, '\\')) {
                $parts = explode('\\', $className);
                $names[] = end($parts);
            }
            $isClass = $i1->constInt(0, false);
            foreach ($names as $name) {
                $nameLit = $context->builder->load($context->constantStringFromString($name));
                $nameData = self::stringDataPtr($context, $nameLit);
                $cmp = $context->builder->call($strcasecmp, $classData, $nameData);
                $isClass = $context->builder->or(
                    $isClass,
                    $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false))
                );
            }
            $hit = $i1->constInt(0, false);
            $classLc = strtolower(ltrim($className, '\\'));
            foreach (self::callableMethodNamesForClass($context, $object, $id, $classLc) as $methodLc) {
                $lit = $context->builder->load($context->constantStringFromString($methodLc));
                $litData = self::stringDataPtr($context, $lit);
                $cmp = $context->builder->call($strcasecmp, $methodData, $litData);
                $match = $context->builder->icmp(
                    Builder::INT_EQ,
                    $cmp,
                    $i32->constInt(0, false)
                );
                $hit = $context->builder->or($hit, $match);
            }
            $exists = $context->builder->select($isClass, $hit, $exists);
        }

        return $exists;
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $off = $context->structFieldIndex($strPtr, 'value');

        return $context->builder->structGep($strPtr, $off);
    }

    /**
     * User methods from methodVisibility (public only) + internal proxies (`class::method`).
     *
     * @return list<string>
     */
    private static function callableMethodNamesForClass(
        Context $context,
        \PHPCompiler\JIT\Builtin\Type\Object_ $object,
        int $classId,
        string $classLc
    ): array {
        $out = self::publicInstanceMethodNames($object, $classId);
        $prefix = $classLc.'::';
        foreach ($context->functionProxies as $lc => $proxy) {
            if (!\is_string($lc) || !str_starts_with($lc, $prefix)) {
                continue;
            }
            if ($proxy instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                continue;
            }
            $methodLc = substr($lc, \strlen($prefix));
            if ('' === $methodLc || '__construct' === $methodLc) {
                continue;
            }
            // User classes store visibility on Object_; honor it so private stays false (#9334).
            if ($object->hasMethod($classId, $methodLc)) {
                $vis = $object->methodVisibility($classId, $methodLc);
                if (!MethodVisibility::isPublic($vis)) {
                    continue;
                }
            }
            if (!\in_array($methodLc, $out, true)) {
                $out[] = $methodLc;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function publicInstanceMethodNames(
        \PHPCompiler\JIT\Builtin\Type\Object_ $object,
        int $classId
    ): array {
        $out = [];
        foreach ($object->declaredMethodNames($classId) as $methodLc) {
            $vis = $object->methodVisibility($classId, $methodLc);
            if (!MethodVisibility::isPublic($vis)) {
                continue;
            }
            $out[] = $methodLc;
        }
        $classLc = strtolower(ltrim($object->classNameForId($classId), '\\'));
        $visited = [$classLc => true];
        $parentLc = $object->parentClassLc($classLc);
        while (null !== $parentLc && !isset($visited[$parentLc])) {
            $visited[$parentLc] = true;
            $parentId = $object->lookup($parentLc);
            foreach ($object->declaredMethodNames($parentId) as $methodLc) {
                $vis = $object->methodVisibility($parentId, $methodLc);
                if (!MethodVisibility::isPublic($vis)) {
                    continue;
                }
                if (!\in_array($methodLc, $out, true)) {
                    $out[] = $methodLc;
                }
            }
            $parentLc = $object->parentClassLc($parentLc);
        }

        return $out;
    }

    private static function jitWriteCallableNameLiteral(Context $context, string $name, JITVariable $nameOut): void
    {
        $outPtr = JitValueBox::valuePtrFromVariable($context, $nameOut);
        $str = $context->builder->load($context->constantStringFromString($name));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $str
        );
    }

    private static function jitWriteCallableNameFromVariable(Context $context, JITVariable $source, JITVariable $nameOut): void
    {
        $outPtr = JitValueBox::valuePtrFromVariable($context, $nameOut);
        $str = JitStringArg::lower($context, $source, 'is_callable() callback');
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $str
        );
    }

    private static function checkCompileTimeString(Context $context, string $name, bool $syntaxOnly): Value
    {
        if (str_contains($name, '::')) {
            [$class, $method] = explode('::', $name, 2);
            $valid = '' !== $class && '' !== $method;
            if ($valid && !$syntaxOnly) {
                $valid = $context->classIsRegistered(strtolower(ltrim($class, '\\')));
            }

            return $context->constantFromInteger($valid ? 1 : 0, 'int1');
        }
        $valid = (bool) preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $name);
        if ($valid && !$syntaxOnly) {
            $valid = $context->functionIsRegistered(strtolower($name));
        }

        return $context->constantFromInteger($valid ? 1 : 0, 'int1');
    }

    private static function checkRuntimeString(Context $context, JITVariable $callback): Value
    {
        $hints = self::hintedFunctionNames($context);
        if ([] === $hints) {
            return $context->constantFromInteger(0, 'int1');
        }
        $nameStr = JitStringArg::lower($context, $callback, 'is_callable() var');
        $candidates = VariableFunctionCallHelper::dispatchCandidates($context, $hints);
        if ([] === $candidates) {
            return $context->constantFromInteger(0, 'int1');
        }
        if (1 === \count($candidates)) {
            $fnName = array_key_first($candidates);
            assert(is_string($fnName));
            $literalStr = $context->builder->load($context->constantStringFromString($fnName));

            return $context->builder->icmp(
                Builder::INT_EQ,
                $nameStr,
                $literalStr
            );
        }
        $i1 = $context->getTypeFromString('int1');
        $result = $i1->constInt(0, false);
        foreach ($candidates as $fnName => $_proxy) {
            $literalStr = $context->builder->load($context->constantStringFromString($fnName));
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $nameStr, $literalStr);
            $result = $context->builder->or($result, $isMatch);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function hintedFunctionNames(Context $context): array
    {
        $block = $context->jitCurrentBlock;
        if (null === $block) {
            return [];
        }

        return array_values(array_unique(array_merge(
            VariableFunctionCallHelper::funDefNamesInCompilationUnit($block),
            VariableFunctionCallHelper::coalesceBranchLiteralHints($block)
        )));
    }
}
