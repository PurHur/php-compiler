<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\RuntimeIndirectInstanceMethodCall;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\EmptyObjectPropertyLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPTypes\Type;
use PHPLLVM\Value;

/**
 * SSOT for JIT ArrayAccess $obj[$key] lowering (Zend read_dimension / write_dimension, #3331, #4012, #10246).
 *
 * php-src: Zend/zend_execute.c — ArrayAccess dimension handlers
 * php-src: Zend/zend_interfaces.c — offsetGet/offsetSet/offsetExists/offsetUnset
 *
 * VM runtime: {@see \PHPCompiler\VM\ArrayAccessDimension}
 */
final class VmArrayAccess
{
    private const IFACE_LC = 'arrayaccess';

    public static function containerImplementsArrayAccess(
        Context $context,
        JitVariable $container,
        ?Operand $containerOp
    ): bool {
        $classLc = self::resolveContainerClassLc($container, $containerOp);
        if (null === $classLc || 'object' === $classLc) {
            return false;
        }

        return in_array(
            self::IFACE_LC,
            $context->type->object->allInterfacesForClassLc($classLc),
            true
        );
    }

    public static function tryCompileDimFetch(
        Context $context,
        JitVariable $container,
        JitVariable $dim,
        ?Operand $containerOp,
        bool $forWrite
    ): ?JitVariable {
        if ($container->isArrayAccessWritableOffset) {
            if ($forWrite) {
                $receiver = $container->writableArrayAccessReceiver;
                if (null === $receiver) {
                    throw new \LogicException('ArrayAccess writable offset missing receiver');
                }
                self::emitIndirectModifyNotice(
                    $context,
                    self::resolveContainerClassLc($receiver, $containerOp) ?? 'ArrayAccess'
                );

                return self::discardAssignTarget($context);
            }

            return self::offsetGet($context, $container->writableArrayAccessReceiver, $dim);
        }

        if (!self::canUseArrayAccess($context, $container, $containerOp)) {
            return null;
        }

        if ($forWrite) {
            return self::writableOffset($context, $container, $dim);
        }

        return self::offsetGet($context, $container, $dim);
    }

    public static function tryCompileOffsetIsSet(
        Context $context,
        JitVariable $container,
        JitVariable $dim,
        ?Operand $containerOp
    ): ?Value {
        if (!self::canUseArrayAccess($context, $container, $containerOp)) {
            return null;
        }

        // ArrayObject/ArrayIterator native has_dimension(isset): null values are unset (#24251).
        if (self::containerUsesNativeSplArrayDimensionIsset($context, $containerOp)) {
            return self::compileNativeSplArrayDimensionIsSet($context, $container, $dim);
        }

        $raw = self::invokeOffsetMethod($context, 'offsetexists', $container, $dim);
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer(
            $context,
            $slot,
            JitValueBox::normalizeValuePtr($context, $raw)
        );
        $boxed = new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
        $boxed->addref();

        return (new \PHPCompiler\ext\standard\boolval())->call($context, $boxed);
    }

    /**
     * Exact ArrayObject / ArrayIterator / RecursiveArrayIterator — php-src fptr_offset_has NULL (#24251).
     * Subclasses with/without overrides are handled on the VM path; opaque JIT keeps offsetExists.
     */
    private static function containerUsesNativeSplArrayDimensionIsset(
        Context $context,
        ?Operand $containerOp
    ): bool {
        $classLc = null;
        if (null !== $containerOp && null !== $containerOp->type && Type::TYPE_OBJECT === $containerOp->type->type) {
            $userType = $containerOp->type->userType ?? '';
            if ('' !== $userType && 'object' !== strtolower(ltrim($userType, '\\'))) {
                $classLc = strtolower(ltrim($userType, '\\'));
            }
        }
        if (null === $classLc) {
            return false;
        }

        return in_array($classLc, ['arrayobject', 'arrayiterator', 'recursivearrayiterator'], true);
    }

    private static function compileNativeSplArrayDimensionIsSet(
        Context $context,
        JitVariable $container,
        JitVariable $dim
    ): Value {
        $existsRaw = self::invokeOffsetMethod($context, 'offsetexists', $container, $dim);
        $existsSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer(
            $context,
            $existsSlot,
            JitValueBox::normalizeValuePtr($context, $existsRaw)
        );
        $existsBoxed = new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $existsSlot);
        $existsBoxed->addref();
        $exists = (new \PHPCompiler\ext\standard\boolval())->call($context, $existsBoxed);

        $tag = 'spl_ao_isset_'.(string) spl_object_id($context).'_'.(string) spl_object_id($container);
        $missingBlock = BasicBlockHelper::append($context, $tag.'_missing');
        $presentBlock = BasicBlockHelper::append($context, $tag.'_present');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $i1 = $context->getTypeFromString('int1');

        $context->builder->branchIf($exists, $presentBlock, $missingBlock);

        $context->builder->positionAtEnd($missingBlock);
        $missing = $i1->constInt(0, false);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($presentBlock);
        $fetched = self::offsetGet($context, $container, $dim);
        // isset: non-null / non-undefined — matches spl_array_has_dimension(check_empty=0).
        $valueMap = $context->structFieldMap['__value__'];
        $valPtr = JitValueBox::valuePtrFromVariable($context, $fetched);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $nullType = $i8->constInt(Variable::TYPE_NULL, false);
        $undefType = $i8->constInt(Variable::TYPE_UNDEFINED, false);
        $notNull = $context->builder->icmp(\PHPLLVM\Builder::INT_NE, $typeByte, $nullType);
        $notUndef = $context->builder->icmp(\PHPLLVM\Builder::INT_NE, $typeByte, $undefType);
        $isSet = $context->builder->and($notNull, $notUndef);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming([$missing, $missingBlock]);
        $phi->addIncoming([$isSet, $presentBlock]);

        return $phi;
    }

    public static function tryCompileOffsetIsEmpty(
        Context $context,
        JitVariable $container,
        JitVariable $dim,
        ?Operand $containerOp
    ): ?Value {
        if (!self::canUseArrayAccess($context, $container, $containerOp)) {
            return null;
        }
        $exists = self::tryCompileOffsetIsSet($context, $container, $dim, $containerOp);
        if (null === $exists) {
            return null;
        }

        $tag = 'aa_empty_'.(string) spl_object_id($context);
        $missingBlock = BasicBlockHelper::append($context, $tag.'_missing');
        $presentBlock = BasicBlockHelper::append($context, $tag.'_present');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $i1 = $context->getTypeFromString('int1');

        $context->builder->branchIf($exists, $presentBlock, $missingBlock);

        $context->builder->positionAtEnd($missingBlock);
        $missingEmpty = $i1->constInt(1, false);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($presentBlock);
        $fetched = self::offsetGet($context, $container, $dim);
        $valueEmpty = EmptyObjectPropertyLlvm::compileEmptyFromValue($context, $fetched);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming([$missingEmpty, $missingBlock]);
        $phi->addIncoming([$valueEmpty, $presentBlock]);

        return $phi;
    }

    public static function tryCompileOffsetUnset(
        Context $context,
        JitVariable $container,
        JitVariable $dim,
        ?Operand $containerOp
    ): bool {
        if (!self::canUseArrayAccess($context, $container, $containerOp)) {
            return false;
        }
        self::invokeOffsetMethod($context, 'offsetunset', $container, $dim);

        return true;
    }

    public static function isKnownNonArrayAccessObject(
        Context $context,
        JitVariable $container,
        ?Operand $containerOp
    ): bool {
        if (JitVariable::TYPE_OBJECT !== $container->type) {
            return false;
        }
        $classLc = self::resolveContainerClassLc($container, $containerOp);
        if (null === $classLc || 'object' === $classLc) {
            return false;
        }

        return !in_array(
            self::IFACE_LC,
            $context->type->object->allInterfacesForClassLc($classLc),
            true
        );
    }

    public static function emitIllegalOffset(Context $context): void
    {
        $message = 'Illegal offset';
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_logic_exception'),
            self::stringDataPtrFromLiteral($context, $message),
            $context->constantFromInteger(strlen($message), 'size_t')
        );
    }

    public static function emitIndirectModifyNotice(Context $context, string $className): void
    {
        $message = sprintf(
            'Indirect modification of overloaded element of %s has no effect',
            $className
        );
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_NOTICE, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    public static function assignWritableOffset(Context $context, JitVariable $lvalue, JitVariable $value): void
    {
        if (null === $lvalue->writableArrayAccessReceiver || null === $lvalue->writableArrayAccessKey) {
            throw new \LogicException('ArrayAccess writable offset missing receiver or key');
        }
        self::invokeOffsetMethod(
            $context,
            'offsetset',
            $lvalue->writableArrayAccessReceiver,
            $lvalue->writableArrayAccessKey,
            $value
        );
    }

    /** True when offsetGet is `&offsetGet` for a statically known ArrayAccess class (#32015). */
    public static function offsetGetReturnsByRefAtCompileTime(
        Context $context,
        JitVariable $receiver,
        ?Operand $receiverOp
    ): bool {
        $classLc = self::resolveContainerClassLc($receiver, $receiverOp);
        if (null !== $classLc && 'object' !== $classLc) {
            return isset($context->functionReturnsRef[strtolower($classLc.'::offsetget')]);
        }
        foreach (self::arrayAccessMethodCandidates($context, 'offsetget') as $candidate) {
            if (
                $candidate instanceof Call\Native
                && isset($context->functionReturnsRef[strtolower($candidate->name)])
            ) {
                return true;
            }
        }

        return false;
    }

    private static function discardAssignTarget(Context $context): JitVariable
    {
        $slot = JitValueBox::alloc($context);
        $var = new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VALUE, $slot);
        $var->addref();

        return $var;
    }

    private static function canUseArrayAccess(
        Context $context,
        JitVariable $container,
        ?Operand $containerOp
    ): bool {
        if (JitVariable::TYPE_OBJECT !== $container->type && JitVariable::TYPE_VALUE !== $container->type) {
            return false;
        }
        $classLc = self::resolveContainerClassLc($container, $containerOp);
        if (null !== $classLc && 'object' !== $classLc) {
            return in_array(
                self::IFACE_LC,
                $context->type->object->allInterfacesForClassLc($classLc),
                true
            );
        }

        return self::hasRuntimeArrayAccessCandidates($context);
    }

    private static function hasRuntimeArrayAccessCandidates(Context $context): bool
    {
        return [] !== self::arrayAccessMethodCandidates($context, 'offsetget');
    }

    private static function writableOffset(
        Context $context,
        JitVariable $container,
        JitVariable $dim
    ): JitVariable {
        $slot = JitValueBox::alloc($context);
        $var = new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
        $var->writableArrayAccessReceiver = $container;
        $var->writableArrayAccessKey = $dim;
        $var->isArrayAccessWritableOffset = true;

        return $var;
    }

    private static function offsetGet(
        Context $context,
        JitVariable $container,
        JitVariable $dim
    ): JitVariable {
        $raw = self::invokeOffsetMethod($context, 'offsetget', $container, $dim);
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer(
            $context,
            $slot,
            JitValueBox::normalizeValuePtr($context, $raw)
        );
        $var = new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
        $var->addref();

        return $var;
    }

    private static function invokeOffsetMethod(
        Context $context,
        string $methodLc,
        JitVariable $receiver,
        JitVariable ...$extraArgs
    ): Value {
        $candidates = self::arrayAccessMethodCandidates($context, $methodLc);
        if ([] === $candidates) {
            throw new \LogicException('No JIT lowering for ArrayAccess::'.$methodLc.'()');
        }
        $call = new RuntimeIndirectInstanceMethodCall($receiver, $methodLc, $candidates);

        return $call->call($context, $receiver, ...$extraArgs);
    }

    /**
     * @return array<int, Call>
     */
    private static function arrayAccessMethodCandidates(Context $context, string $methodLc): array
    {
        $methodLc = strtolower($methodLc);
        $candidates = [];
        foreach ($context->type->object->allClassNamesById() as $classId => $className) {
            $classLc = strtolower(ltrim($className, '\\'));
            if (!in_array(self::IFACE_LC, $context->type->object->allInterfacesForClassLc($classLc), true)) {
                continue;
            }
            $proxyName = $classLc.'::'.$methodLc;
            if (!$context->functionIsRegistered($proxyName)) {
                continue;
            }
            $candidates[$classId] = $context->resolveFunctionProxy($proxyName);
        }

        return $candidates;
    }

    public static function resolveContainerClassLc(
        JitVariable $container,
        ?Operand $containerOp
    ): ?string {
        if (null !== $containerOp && null !== $containerOp->type && Type::TYPE_OBJECT === $containerOp->type->type) {
            $userType = $containerOp->type->userType ?? '';
            if ('' !== $userType && 'object' !== strtolower(ltrim($userType, '\\'))) {
                $lc = strtolower(ltrim($userType, '\\'));
                // php-types InternalArgInfo typo: simplexml_load_* → simplemxml_element (#25338).
                if ('simplemxml_element' === $lc) {
                    return 'simplexmlelement';
                }

                return $lc;
            }
        }

        return null;
    }

    private static function stringDataPtrFromLiteral(Context $context, string $message): Value
    {
        return $context->builder->pointerCast(
            $context->constantFromString($message),
            $context->getTypeFromString('int8*')
        );
    }
}
