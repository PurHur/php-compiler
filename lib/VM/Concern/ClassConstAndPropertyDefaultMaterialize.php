<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Func;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClosureRichDisplayName;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;

/**
 * Deferred class-const segments + property-default / class-body init materialize for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code collectClassConstSegments} through
 * {@code executeClassBodyDefaultInitOpcode} (php-src Zend/zend_compile.c
 * zend_compile_const_expr / class property defaults; Zend/zend_API.c object init;
 * Reflection defaults — #5362, #9767, #5443, #11239, #4385, #26240). Companion to
 * {@see ClassInheritDefineAndConstDeclare} / {@see InheritanceFinalVarianceAndConstFetch}.
 * Concern trait — same namespace as parent so relative Frame / OpCode / Block helpers resolve.
 * Move-only; no new C ABI.
 */
trait ClassConstAndPropertyDefaultMaterialize
{
    /**
     * @param list<OpCode> $classBodyOps
     * @return array<string, array{initIndices: list<int>, declareIndex: int}>
     */
    private function collectClassConstSegments(array $classBodyOps, Frame $frame): array
    {
        $segments = [];
        /** @var list<int> $pendingInitIndices */
        $pendingInitIndices = [];
        $inNewFragment = false;
        foreach ($classBodyOps as $index => $op) {
            if (OpCode::TYPE_DECLARE_CLASS_CONST === $op->type) {
                $name = ClassConstName::key($frame->scope[$op->arg1]->toString());
                $segments[$name] = [
                    'initIndices' => $pendingInitIndices,
                    'declareIndex' => $index,
                ];
                $pendingInitIndices = [];
                $inNewFragment = false;

                continue;
            }
            if ($inNewFragment) {
                $pendingInitIndices[] = $index;

                continue;
            }
            if ($this->isClassConstSegmentInitOpcode($op->type)) {
                $pendingInitIndices[] = $index;
                if (OpCode::TYPE_NEW === $op->type) {
                    $inNewFragment = true;
                }
            } elseif ([] !== $pendingInitIndices) {
                $pendingInitIndices = [];
            }
        }

        return $segments;
    }

    /**
     * @param array<string, array{initIndices: list<int>, declareIndex: int}> $segments
     * @return array<int, true>
     */
    private function classConstSegmentSkipIndices(array $segments): array
    {
        $skip = [];
        foreach ($segments as $segment) {
            foreach ($segment['initIndices'] as $index) {
                $skip[$index] = true;
            }
            $skip[$segment['declareIndex']] = true;
        }

        return $skip;
    }

    private function isClassConstSegmentInitOpcode(int $type): bool
    {
        return VM\ClassConstExpr::isSupportedOpcode($type)
            || $this->isClassBodyConstInitOpcode($type);
    }

    /**
     * @param array<string, array{initIndices: list<int>, declareIndex: int}> $segments
     * @return array<string, array{initIndices: list<int>, declareIndex: int}>
     */
    private function deferredClassConstSegments(array $segments): array
    {
        $deferred = [];
        foreach ($segments as $lcName => $segment) {
            if ([] !== $segment['initIndices']) {
                $deferred[$lcName] = $segment;
            }
        }

        return $deferred;
    }

    /**
     * @param list<OpCode> $classBodyOps
     * @param array<string, array{initIndices: list<int>, declareIndex: int}> $segments
     * @return array<string, array{initIndices: list<int>, declareIndex: int}>
     */
    private function finalizeDeferredClassConstants(
        ClassEntry $entry,
        Block $block,
        Frame $frame,
        array $classBodyOps,
        array $segments
    ): array {
        /** @var list<string> $pending */
        $pending = array_keys($segments);
        $maxPasses = \count($pending) + 1;
        for ($pass = 0; $pass < $maxPasses && [] !== $pending; ++$pass) {
            /** @var list<string> $stillPending */
            $stillPending = [];
            $madeProgress = false;
            foreach ($pending as $lcName) {
                if (isset($entry->constants[$lcName])) {
                    continue;
                }
                try {
                    $this->evaluateDeferredClassConstSegment(
                        $entry,
                        $block,
                        $frame,
                        $classBodyOps,
                        $segments[$lcName]
                    );
                    $madeProgress = true;
                } catch (VM\ClassConstForwardReferenceException) {
                    $stillPending[] = $lcName;
                }
            }
            if (!$madeProgress) {
                break;
            }
            $pending = $stillPending;
        }
        if ([] !== $pending) {
            $entry->forwardDeclaredConstNames = array_fill_keys($pending, true);
            $stillPending = [];
            foreach ($pending as $lcName) {
                $stillPending[$lcName] = $segments[$lcName];
            }

            return $stillPending;
        }
        $entry->forwardDeclaredConstNames = null;
        $entry->pendingConstMaterialization = null;

        return [];
    }

    /**
     * Lazy-evaluate a forward-declared class constant (zend_get_class_constant_ex / #31837).
     *
     * {@code $markVisited} mirrors IS_CONSTANT_VISITED: nested fetches mark; the outer
     * FETCH_CLASS_CONSTANT path does not (so mutual cycles report the peer name).
     */
    public function materializePendingClassConstant(
        ClassEntry $entry,
        string $constName,
        bool $markVisited = true,
        string $fetchClassName = 'self'
    ): void {
        if (isset($entry->constants[$constName])) {
            return;
        }
        if ($markVisited && isset($entry->visitedConstNames[$constName])) {
            throw new \Error(
                VM\ClassConstExpr::selfReferencingConstantMessage($entry, $constName, $fetchClassName)
            );
        }
        $pending = $entry->pendingConstMaterialization;
        if (null === $pending || !isset($pending['segments'][$constName])) {
            $this->hydratePendingConstMaterializationFromDeferred($entry);
            $pending = $entry->pendingConstMaterialization;
        }
        if (null === $pending || !isset($pending['segments'][$constName])) {
            return;
        }
        if ($markVisited) {
            $entry->visitedConstNames[$constName] = true;
        }
        $prevLazy = $entry->lazyConstMaterialize;
        $entry->lazyConstMaterialize = true;
        try {
            $this->evaluateDeferredClassConstSegment(
                $entry,
                $pending['block'],
                $pending['frame'],
                $pending['classBodyOps'],
                $pending['segments'][$constName]
            );
            unset($entry->forwardDeclaredConstNames[$constName]);
            if (null !== $entry->forwardDeclaredConstNames && [] === $entry->forwardDeclaredConstNames) {
                $entry->forwardDeclaredConstNames = null;
            }
            unset($entry->pendingConstMaterialization['segments'][$constName]);
            if (
                null !== $entry->pendingConstMaterialization
                && [] === $entry->pendingConstMaterialization['segments']
            ) {
                $entry->pendingConstMaterialization = null;
            }
            $this->removeDeferredClassConstSegment($entry, $constName);
        } finally {
            $entry->lazyConstMaterialize = $prevLazy;
            if ($markVisited) {
                unset($entry->visitedConstNames[$constName]);
            }
        }
    }

    private function hydratePendingConstMaterializationFromDeferred(ClassEntry $entry): void
    {
        foreach ($this->context->deferredClassConstants as $deferred) {
            if ($deferred['entry'] !== $entry) {
                continue;
            }
            $entry->pendingConstMaterialization = [
                'block' => $deferred['block'],
                'frame' => $deferred['frame'],
                'classBodyOps' => $deferred['classBodyOps'],
                'segments' => $deferred['segments'],
            ];

            return;
        }
    }

    private function removeDeferredClassConstSegment(ClassEntry $entry, string $constName): void
    {
        $remaining = [];
        foreach ($this->context->deferredClassConstants as $deferred) {
            if ($deferred['entry'] !== $entry) {
                $remaining[] = $deferred;
                continue;
            }
            unset($deferred['segments'][$constName]);
            if ([] !== $deferred['segments']) {
                $remaining[] = $deferred;
            }
        }
        $this->context->deferredClassConstants = $remaining;
    }

    /**
     * @param list<OpCode> $classBodyOps
     * @param array{initIndices: list<int>, declareIndex: int} $segment
     */
    private function evaluateDeferredClassConstSegment(
        ClassEntry $entry,
        Block $block,
        Frame $frame,
        array $classBodyOps,
        array $segment
    ): void {
        $declareOp = $classBodyOps[$segment['declareIndex']];
        $initOps = [];
        foreach ($segment['initIndices'] as $index) {
            $initOps[] = $classBodyOps[$index];
        }
        $newResultSlot = $this->classConstNewFragmentResultSlot($initOps);
        if (null !== $newResultSlot) {
            $value = $this->executePropertyDefaultInitBlock(
                $block->fragmentForOpcodes($initOps),
                $newResultSlot
            );
            if (!isset($frame->scope[$declareOp->arg2])) {
                $frame->scope[$declareOp->arg2] = new Variable();
            }
            $frame->scope[$declareOp->arg2]->copyFrom($value);
        } else {
            foreach ($initOps as $op) {
                if (VM\ClassConstExpr::isSupportedOpcode($op->type)) {
                    VM\ClassConstExpr::execute($this->context, $frame, $block, $op, $entry);
                } elseif ($this->isClassBodyConstInitOpcode($op->type)) {
                    $this->executeClassBodyConstInitOpcode($frame, $op);
                } else {
                    throw new \LogicException(
                        'Unexpected class constant init opcode: '.opcode_type_name($op->type)
                    );
                }
            }
        }
        $this->applyClassConstDeclaration(
            $entry,
            $block,
            $frame,
            $declareOp
        );
    }

    /**
     * @param list<OpCode> $pendingNewDefaultOps
     */
    private function finalizePendingNewClassConst(
        Frame $frame,
        Block $block,
        OpCode $declareOp,
        array $pendingNewDefaultOps
    ): void {
        $resultSlot = $this->classConstNewFragmentResultSlot($pendingNewDefaultOps);
        if (null === $resultSlot) {
            foreach ($pendingNewDefaultOps as $pendingOp) {
                $this->executeClassBodyConstInitOpcode($frame, $pendingOp);
            }

            return;
        }
        $value = $this->executePropertyDefaultInitBlock(
            $block->fragmentForOpcodes($pendingNewDefaultOps),
            $resultSlot
        );
        if (!isset($frame->scope[$declareOp->arg2])) {
            $frame->scope[$declareOp->arg2] = new Variable();
        }
        $frame->scope[$declareOp->arg2]->copyFrom($value);
    }

    /**
     * @param list<OpCode> $initOps
     */
    private function classConstNewFragmentResultSlot(array $initOps): ?int
    {
        foreach ($initOps as $initOp) {
            if (OpCode::TYPE_NEW === $initOp->type) {
                return $initOp->arg1;
            }
        }

        return null;
    }

    /**
     * @param list<OpCode> $pendingNewDefaultOps
     */
    private function finalizePendingNewPropertyDefault(
        Frame $frame,
        Block $block,
        ClassEntry $entry,
        OpCode $declareOp,
        array $pendingNewDefaultOps
    ): void {
        $resultSlot = null;
        foreach ($pendingNewDefaultOps as $initOp) {
            if (OpCode::TYPE_NEW === $initOp->type) {
                $resultSlot = $initOp->arg1;
                break;
            }
        }
        if (null === $resultSlot) {
            throw new \LogicException('Property default `new` initializer missing TYPE_NEW');
        }

        if (OpCode::TYPE_DECLARE_STATIC_PROPERTY === $declareOp->type) {
            $value = $this->executePropertyDefaultInitBlock(
                $block->fragmentForOpcodes($pendingNewDefaultOps),
                $resultSlot
            );
            $name = strtolower($frame->scope[$declareOp->arg1]->toString());
            $storage = $this->cloneStaticPropertyStorage($frame->scope[$declareOp->arg3]);
            $storage->copyFrom($value);
            $this->linkStaticTypedPropertySlot(
                $storage,
                $entry,
                $frame->scope[$declareOp->arg1]->toString()
            );
            $entry->staticProperties[$name] = $storage;
            $entry->staticPropertyVisibility[$name] = MethodVisibility::mask($declareOp->propertyVisibility);
            $entry->staticPropertySetVisibility[$name] = (int) ($declareOp->propertySetVisibility ?? 0);
            $entry->staticPropertyGetVisibility[$name] = (int) ($declareOp->propertyGetVisibility ?? 0);
            if ($declareOp->propertyAsymmetricExplicitRead ?? false) {
                $entry->staticPropertyAsymmetricExplicitRead[$name] = true;
            }
            $entry->staticPropertyDeclaringClassLc[$name] = strtolower($entry->name);
            if (!empty($declareOp->propertyFinal)) {
                $entry->staticPropertyFinal[$name] = true;
            } else {
                unset($entry->staticPropertyFinal[$name]);
            }

            return;
        }

        $property = new VM\ClassProperty(
            $frame->scope[$declareOp->arg1]->toString(),
            null,
            $frame->scope[$declareOp->arg3],
            $declareOp->propertyReadonly,
            MethodVisibility::mask($declareOp->propertyVisibility),
            strtolower($entry->name),
            (int) ($declareOp->propertySetVisibility ?? 0),
            (int) ($declareOp->propertyGetVisibility ?? 0),
            (bool) ($declareOp->propertyAsymmetricExplicitRead ?? false),
            (bool) ($declareOp->propertyLazy ?? false)
        );
        $property->defaultInitBlock = $block->fragmentForOpcodes($pendingNewDefaultOps);
        $property->defaultInitResultSlot = $resultSlot;
        if ($entry->readonly) {
            $property->readonly = true;
        }
        $property->setVisibility = PropertyVisibility::withImplicitReadonlyProtectedSet(
            $property->readonly,
            MethodVisibility::mask($property->visibility),
            (int) $property->setVisibility
        );
        // php-src zend_API.c — private(set) ⇒ ZEND_ACC_FINAL (#23068).
        $property->propertyFinal = (bool) ($declareOp->propertyFinal ?? false)
            || PropertyVisibility::isImplicitlyFinalFromPrivateSet(
                (int) $property->setVisibility
            );
        $entry->properties[] = $property;
    }

    public function initInstancePropertyDefaults(ObjectEntry $object): void
    {
        foreach ($object->class->properties as $property) {
            if ($property->lazy) {
                continue;
            }
            if (!$property->hasRuntimeDefaultInit()) {
                continue;
            }
            assert(null !== $property->defaultInitBlock);
            assert(null !== $property->defaultInitResultSlot);
            $value = $this->executePropertyDefaultInitBlock(
                $property->defaultInitBlock,
                $property->defaultInitResultSlot
            );
            $slot = $object->getProperty($property->name);
            $slot->copyFrom($value);
            $strict = false;
            TypeCheck::coercePropertyWrite($slot, $strict);
        }
    }

    /**
     * Reapply a declared property default during clone-with property list (#10310, Zend/zend_clones.c).
     */
    public function reinitCloneWithProperty(ObjectEntry $object, string $propName): void
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta) {
            throw new \Error(sprintf(
                'Cannot reinitialize property %s::$%s',
                $object->class->name,
                $propName
            ));
        }
        $slot = $object->getProperty($propName);
        if ($meta->hasRuntimeDefaultInit()) {
            assert(null !== $meta->defaultInitBlock);
            assert(null !== $meta->defaultInitResultSlot);
            $value = $this->executePropertyDefaultInitBlock(
                $meta->defaultInitBlock,
                $meta->defaultInitResultSlot
            );
            $slot->copyFrom($value);
        } elseif (null !== $meta->default) {
            $slot->copyFrom($meta->default);
        } else {
            $slot->copyFrom($meta->getVariable());
        }
        TypeCheck::coercePropertyWrite($slot, false);
    }

    /**
     * `new Class(...)` first-class callable invoke (#9767, zend_compile.c).
     *
     * @param list<Variable> $ctorArgs
     */
    /**
     * ReflectionClass::newInstanceWithoutConstructor() object allocation (#5443, zend_objects.c).
     */
    public function allocateObjectWithoutConstructor(ClassEntry $class): ObjectEntry
    {
        VM\ReservedBuiltinClass::assertUserInstantiable($class);
        if ($class->isEnum) {
            throw new \Error("Cannot instantiate enum {$class->name}");
        }
        if ($class->isInterface) {
            throw new \Error("Cannot instantiate interface {$class->name}");
        }
        if ($class->isTrait) {
            throw new \Error("Cannot instantiate trait {$class->name}");
        }
        if ($class->isAbstract) {
            throw new \Error("Cannot instantiate abstract class {$class->name}");
        }
        $object = new ObjectEntry($class);
        $this->initInstancePropertyDefaults($object);
        if (null === $class->constructor && !$this->hasInstanceMethod($class, '__construct')) {
            $object->constructed = true;
        }

        return $object;
    }

    public function instantiateFromNewCallable(ClassEntry $class, Frame $frame, Variable ...$ctorArgs): ObjectEntry
    {
        VM\ReservedBuiltinClass::assertUserInstantiable($class);
        if ($class->isEnum) {
            throw new \Error("Cannot instantiate enum {$class->name}");
        }
        if ($class->isAbstract) {
            throw new \Error("Cannot instantiate abstract class {$class->name}");
        }
        if ($class->isInterface) {
            throw new \Error("Cannot instantiate interface {$class->name}");
        }
        if ($class->isTrait) {
            throw new \Error("Cannot instantiate trait {$class->name}");
        }
        VM\ClassValidator::assertInstantiable($class);
        if (null !== $class->constructor || $this->hasInstanceMethod($class, '__construct')) {
            // Unadvertised internal constructors skip visibility resolve (#22789).
            if ($this->hasInstanceMethod($class, '__construct')) {
                try {
                    [$declaringClass, $methodLc] = $this->resolveInstanceMethod($class, '__construct');
                    $vis = $declaringClass->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
                    $callerClassLc = $this->callerClassLc($frame);
                    $callerDisplay = $this->callerScopeDisplay($frame, $callerClassLc);
                    MethodVisibility::assertConstructorCallable(
                        $vis,
                        $callerClassLc,
                        strtolower($declaringClass->name),
                        $declaringClass->name,
                        false,
                        fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                        $callerDisplay
                    );
                } catch (\LogicException $e) {
                    throw new \Error($e->getMessage());
                }
            }
        }
        $this->emitClassInstantiationDeprecation($class, $frame);
        $object = new ObjectEntry($class);
        $this->initInstancePropertyDefaults($object);
        $thisVar = new Variable(Variable::TYPE_OBJECT);
        $thisVar->object($object);
        if (null !== $object->constructor) {
            $this->invokePhpFunction($object->constructor, $thisVar, ...$ctorArgs);
        }
        $object->constructed = true;

        return $object;
    }

    /** Evaluate declared default for ReflectionProperty::getDefaultValue() (#11239). */
    public function evaluatePropertyDefaultForReflection(VM\ClassProperty $property): ?Variable
    {
        // Promoted ctor props: param default is not a property default (#22046).
        if ($property->fromConstructorPromotion) {
            return null;
        }
        if (null !== $property->default && !$property->hasRuntimeDefaultInit()) {
            $copy = new Variable();
            $copy->copyFrom($property->default);

            return $copy;
        }
        if ($property->hasRuntimeDefaultInit()) {
            return $this->executePropertyDefaultInitBlock(
                $property->defaultInitBlock,
                $property->defaultInitResultSlot
            );
        }
        // Untyped property without initializer: Zend implicit null (#22047).
        if (!$property->fromConstructorPromotion && !$property->hasDeclaredType()) {
            $copy = new Variable();
            $copy->null();

            return $copy;
        }

        return null;
    }

    /** Evaluate declared default for ReflectionParameter::getDefaultValue() (#4385, ext/reflection/php_reflection.c). */
    public function evaluateParameterDefaultForReflection(Block $block, int $paramIndex): ?Variable
    {
        if (VM\ReflectionSupport::parameterIsVariadic($block, $paramIndex)) {
            return null;
        }
        if (isset($block->paramRuntimeDefaultInitBlocks[$paramIndex])) {
            $initBlock = $block->paramRuntimeDefaultInitBlocks[$paramIndex];
            $resultSlot = $block->paramRuntimeDefaultResultSlots[$paramIndex] ?? null;
            if (null === $resultSlot) {
                return null;
            }
            $copy = new Variable();
            $copy->copyFrom($this->executePropertyDefaultInitBlock($initBlock, $resultSlot));

            return $copy;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type || (int) $op->arg2 !== $paramIndex) {
                continue;
            }
            if (null === $op->arg3 || !isset($block->constants[$op->arg3])) {
                return null;
            }
            $default = $block->constants[$op->arg3];
            $copy = new Variable();
            if (VM\EnumCaseSupport::isEnumCaseVariable($default)) {
                $copy->copyFrom(
                    VM\EnumCaseSupport::materializeConstantValue($this->context, $default)
                );
            } else {
                $copy->copyFrom($default);
            }

            return $copy;
        }

        return null;
    }

    public function materializeClassConstInitFragment(Block $fragmentBlock, int $resultSlot): Variable
    {
        return $this->executePropertyDefaultInitBlock($fragmentBlock, $resultSlot);
    }

    private function executePropertyDefaultInitBlock(Block $initBlock, int $resultSlot): Variable
    {
        $initFrame = $initBlock->getFrame($this->context);
        // Nested runFrames must not jump into an outer user try/catch — that resumes the
        // caller after catch and re-runs trailing opcodes (#24138; same shape as #14104).
        $prevDefer = $this->context->deferBuiltinCallbackCatchToOuterRunFrames;
        $this->context->deferBuiltinCallbackCatchToOuterRunFrames = true;
        try {
            $this->context->push($initFrame);
            $status = $this->runFrames();
        } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
            throw $redirect;
        } finally {
            $this->context->deferBuiltinCallbackCatchToOuterRunFrames = $prevDefer;
        }
        if (self::SUCCESS !== $status) {
            throw new \LogicException('Property default `new` initializer failed');
        }
        if (!isset($initFrame->scope[$resultSlot])) {
            throw new \LogicException('Property default `new` initializer missing result slot');
        }

        return $initFrame->scope[$resultSlot]->resolveIndirect();
    }

    public function isClassBodyConstInitOpcode(int $type): bool
    {
        return $this->isClassBodyDefaultInitOpcode($type)
            || OpCode::TYPE_NEW === $type
            || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $type
            || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $type
            || OpCode::TYPE_CLOSURE === $type
            || OpCode::TYPE_FROM_CALLABLE === $type;
    }

    private function isClassBodyDefaultInitOpcode(int $type): bool
    {
        return OpCode::TYPE_INIT_ARRAY === $type
            || OpCode::TYPE_ADD_ARRAY_ELEMENT === $type
            || OpCode::TYPE_ARRAY_SPREAD === $type;
    }

    /**
     * INIT_ARRAY (etc.) emitted before property/class-const `new` defaults — defer to the pending fragment (#5362).
     *
     * @param list<OpCode> $classBodyOps
     */
    private function opcodePrecedesPropertyDefaultNew(array $classBodyOps, int $index): bool
    {
        $count = \count($classBodyOps);
        for ($i = $index + 1; $i < $count; ++$i) {
            $type = $classBodyOps[$i]->type;
            if (OpCode::TYPE_NEW === $type) {
                return true;
            }
            if (
                OpCode::TYPE_DECLARE_PROPERTY === $type
                || OpCode::TYPE_DECLARE_STATIC_PROPERTY === $type
                || OpCode::TYPE_DECLARE_CLASS_CONST === $type
            ) {
                return false;
            }
            if (!$this->isClassBodyDefaultInitOpcode($type)) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param list<OpCode> $classBodyOps
     * @return list<OpCode>
     */
    private function collectPropertyDefaultNewPreludeOps(array $classBodyOps, int $newIndex): array
    {
        $prelude = [];
        for ($i = $newIndex - 1; $i >= 0; --$i) {
            if (!$this->isClassBodyDefaultInitOpcode($classBodyOps[$i]->type)) {
                break;
            }
            array_unshift($prelude, $classBodyOps[$i]);
        }

        return $prelude;
    }

    /**
     * @return array<string, true>
     */
    private function classBodyOwnMethodNames(Block $block, Frame $frame): array
    {
        $methods = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_METHOD !== $op->type) {
                continue;
            }
            if (null === $op->block1) {
                continue;
            }
            $methods[strtolower($frame->scope[$op->arg1]->toString())] = true;
        }

        return $methods;
    }

    public function executeClassBodyConstInitOpcode(Frame $frame, OpCode $op): void
    {
        if ($this->isClassBodyDefaultInitOpcode($op->type)) {
            $this->executeClassBodyDefaultInitOpcode($frame, $op);

            return;
        }
        switch ($op->type) {
            case OpCode::TYPE_NEW:
                $result = $frame->scope[$op->arg1];
                // Same classname operand rules as runtime TYPE_NEW (#30058).
                $name = VM\InstanceOfClassName::resolveClassNamePreservingCase(
                    $frame->scope[$op->arg2]
                );
                $lcname = strtolower($name);
                if (!isset($this->context->classes[$lcname])) {
                    $this->context->autoloadClass($name);
                }
                if (!isset($this->context->classes[$lcname])) {
                    throw new \Error($this->classNotFoundMessage($name));
                }
                $class = $this->context->classes[$lcname];
                VM\ReservedBuiltinClass::assertUserInstantiable($class);
                if ($class->isEnum) {
                    throw new \Error("Cannot instantiate enum {$class->name}");
                }
                if ($class->isInterface) {
                    throw new \Error("Cannot instantiate interface {$class->name}");
                }
                if ($class->isTrait) {
                    throw new \Error("Cannot instantiate trait {$class->name}");
                }
                if ($class->isAbstract) {
                    throw new \Error("Cannot instantiate abstract class {$class->name}");
                }
                VM\ClassValidator::assertInstantiable($class);
                $this->enforceNewConstructorVisibility($class, $frame);
                $this->emitClassInstantiationDeprecation($class, $frame);
                $object = new VM\ObjectEntry($class);
                $result->object($object);
                $frame->call = $object->constructor;
                $frame->callArgs = [$result];
                $frame->callArgEntries = [];
                if (null === $frame->call) {
                    $object->constructed = true;
                }
                break;
            case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
            case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                if (is_null($frame->call)) {
                    $this->markPendingNewObjectConstructed($frame);
                    break;
                }
                if ($frame->call instanceof Func\PHP && $frame->call->block->isGenerator) {
                    throw new \LogicException('Generator constructors are not allowed in class constants');
                }
                $new = $frame->call->getFrame($this->context, $frame);
                $new->calledClass = $this->inferCalledClass($frame);
                $new->returnVar = null;
                try {
                    $new->calledArgs = $this->resolveOutgoingCallArgs($frame);
                } catch (\Error $e) {
                    throw $e;
                } catch (\LogicException $e) {
                    throw new \LogicException($e->getMessage(), 0, $e);
                }
                $frame->call = null;
                $this->clearOutgoingCallState($frame);
                $new->parent = $frame;
                $new->vmContext = $this->context;
                $new->ephemeral = true;
                $this->context->push($frame);
                $this->context->push($new);
                $result = $this->runFrames();
                if (self::SUCCESS !== $result) {
                    throw new \LogicException('Class constant constructor failed');
                }
                break;
            case OpCode::TYPE_FROM_CALLABLE:
                // Closures / FCC in class const exprs (#26240, fcc_in_const_expr).
                if (isset($frame->scope[$op->arg2])) {
                    $callable = $frame->scope[$op->arg2]->resolveIndirect();
                } elseif (isset($frame->block->constants[$op->arg2])) {
                    $callable = $frame->block->constants[$op->arg2];
                } else {
                    throw new \LogicException('TYPE_FROM_CALLABLE missing callable slot');
                }
                $entry = VM\ClosureSupport::fromCallable(
                    $this->context,
                    $frame,
                    $callable,
                    $op->fromCallableScope,
                    $op->fromCallableApi
                );
                $frame->scope[$op->arg1]->object($entry);
                break;
            case OpCode::TYPE_CLOSURE:
                // Static Closures in class const exprs (#26240, closures_in_const_expr).
                if (null === $op->block1) {
                    $frame->scope[$op->arg1]->null();
                    break;
                }
                $funcName = null !== $op->block1->func
                    ? $op->block1->func->name
                    : '{closure}';
                $closureFunc = new Func\PHP($funcName, $op->block1);
                $closureFunc->sourceLocation = $op->sourceLocation;
                if ([] !== $op->parameterMetadata) {
                    $closureFunc->parameterMetadata = $op->parameterMetadata;
                }
                if ([] !== $op->attributeNames) {
                    $closureFunc->attributeNames = $op->attributeNames;
                }
                if ([] !== $op->attributeEntries) {
                    $closureFunc->attributeEntries = $op->attributeEntries;
                }
                $captures = $this->bindClosureCaptures($frame, $op->closureCaptures);
                $state = new ClosureState($closureFunc, $captures);
                $state->applyDefinitionSite($op->sourceLocation, $op->block1);
                $rich = ClosureRichDisplayName::preferFromOp($op, $op->block1);
                if (null !== $rich && '' !== $rich) {
                    $state->richDisplayName = $rich;
                }
                if (
                    (null === $state->boundScopeClass || '' === $state->boundScopeClass)
                    && null !== $op->closureDeclaringClass
                    && '' !== $op->closureDeclaringClass
                ) {
                    $state->boundScopeClass = $op->closureDeclaringClass;
                }
                if (
                    null !== $frame->block->func
                    && null !== $frame->block->func->class
                    && null !== $frame->block->func->class->value
                    && '' !== $frame->block->func->class->value
                ) {
                    $declaring = $frame->block->func->class->value;
                    if (null !== $op->block1->func) {
                        $op->block1->func->class = $frame->block->func->class;
                    }
                    $state->boundScopeClass = $declaring;
                    $called = $this->inferCalledClass($frame);
                    if (null !== $called && '' !== $called) {
                        $state->boundCalledScopeClass = $called;
                    }
                }
                $frame->scope[$op->arg1]->object($state->wrapObject($this->context));
                break;
            default:
                throw new \LogicException(
                    'Unexpected class constant init opcode: '.opcode_type_name($op->type)
                );
        }
    }

    private function executeClassBodyDefaultInitOpcode(Frame $frame, OpCode $op): void
    {
        switch ($op->type) {
            case OpCode::TYPE_INIT_ARRAY:
                $result = $frame->scope[$op->arg1];
                $result->newArray();
                if (is_null($op->arg2)) {
                    break;
                }
                // Fall through intentional
            case OpCode::TYPE_ADD_ARRAY_ELEMENT:
                $result = $frame->scope[$op->arg1];
                $ht = $result->toArray();
                if (is_null($op->arg3)) {
                    $ht->append($this->resolveOutgoingCallArgValue($frame, $op->arg2));

                    break;
                }
                $key = $this->resolveOutgoingCallArgValue($frame, $op->arg3)->resolveIndirect();
                $value = $this->resolveOutgoingCallArgValue($frame, $op->arg2);
                // Class-body array defaults: same typed offset TypeError as runtime literals (#28628).
                // Resource keys warn+cast (#29550).
                $key = VM\HashTable::normalizeIndexKeyForWrite($key, $this->context, $frame);
                $storeIndirect = $value->isIndirect();
                if ($key->is(Variable::TYPE_INTEGER) || $key->is(Variable::TYPE_FLOAT)) {
                    $intKey = $key->is(Variable::TYPE_FLOAT)
                        ? \PHPCompiler\ext\standard\VmMath::floatToZendLong($key->toFloat())
                        : $key->toInt();
                    $storeIndirect
                        ? $ht->updateIndirectIndex($intKey, $value)
                        : $ht->updateIndex($intKey, $value);
                } elseif ($key->is(Variable::TYPE_STRING)) {
                    $storeIndirect
                        ? $ht->updateIndirect($key->toString(), $value)
                        : $ht->update($key->toString(), $value);
                } else {
                    throw new \TypeError(VM\EnumCaseSupport::illegalArrayOffsetMessage($key));
                }
                break;
            case OpCode::TYPE_ARRAY_SPREAD:
                $result = $frame->scope[$op->arg1];
                $source = $frame->scope[$op->arg2];
                VM\ArraySpread::spreadInto(
                    $this,
                    $frame,
                    $result->toArray(),
                    $source,
                    (int) ($op->arg3 ?? 0)
                );
                break;
            default:
                throw new \LogicException(
                    'Unexpected class body init opcode: '.opcode_type_name($op->type)
                );
        }
    }
}
