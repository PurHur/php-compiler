<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\ClosureHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmLazyObject;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ReflectionClass::getLazyInitializer() — VM + JIT (#5968, #29152).
 *
 * php-src: Zend/zend_lazy_objects.c / ext/reflection/php_reflection.c —
 * returns the same Closure instance passed to newLazyGhost / newLazyProxy.
 */
final class ReflectionClassGetLazyInitializer extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getLazyInitializer');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::getLazyInitializer() expects an object');
        }
        $objectVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            throw new \TypeError('ReflectionClass::getLazyInitializer(): Argument #1 ($object) must be of type object');
        }
        $closure = LazyObjectSupport::getInitializerClosure($objectVar->toObject());
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $closure) {
            // Legacy path: ClosureState without retained ObjectEntry — wrap once.
            $initializer = LazyObjectSupport::getInitializer($objectVar->toObject());
            if (null === $initializer) {
                $frame->returnVar->null();

                return;
            }
            $ctx = $frame->vmContext;
            if (null === $ctx) {
                throw new \LogicException('ReflectionClass::getLazyInitializer() requires VM context');
            }
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object(ClosureSupport::wrapState($ctx, $initializer));
            $frame->returnVar->copyFrom($out);

            return;
        }
        $out = new Variable(Variable::TYPE_OBJECT);
        $out->object($closure);
        $frame->returnVar->copyFrom($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('ReflectionClass::getLazyInitializer() expects an object');
        }
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[1]);
        $map = $context->structFieldMap['__object__'];
        $pending = $context->builder->load(
            $context->builder->structGep($obj, $map[VmLazyObject::FIELD_LAZY_PENDING])
        );
        $initIndex = $context->builder->load(
            $context->builder->structGep($obj, $map[VmLazyObject::FIELD_LAZY_INIT_INDEX])
        );

        $resultSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $resultSlot)
        );

        $fn = BasicBlockHelper::parentFunction($context);
        $pendingBlock = $fn->appendBasicBlock('get_lazy_init_pending');
        $done = $fn->appendBasicBlock('get_lazy_init_done');
        $i8 = $context->getTypeFromString('int8');
        $isPending = $context->builder->icmp(
            Builder::INT_NE,
            $pending,
            $i8->constInt(0, false)
        );
        $context->builder->branchIf($isPending, $pendingBlock, $done);

        $context->builder->positionAtEnd($pendingBlock);
        $check = $pendingBlock;
        foreach ($context->lazyInitClosures as $idx => $closureVar) {
            $match = $fn->appendBasicBlock('get_lazy_init_match_'.$idx);
            $next = $fn->appendBasicBlock('get_lazy_init_next_'.$idx);
            $context->builder->positionAtEnd($check);
            $isIdx = $context->builder->icmp(
                Builder::INT_EQ,
                $initIndex,
                $context->constantFromInteger((int) $idx, 'int32')
            );
            $context->builder->branchIf($isIdx, $match, $next);
            $context->builder->positionAtEnd($match);
            $closureObj = ClosureHelper::loadObjectFromCallable($context, $closureVar);
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                JitValueBox::pointer($context, $resultSlot),
                $closureObj
            );
            $context->builder->branch($done);
            $check = $next;
        }
        $context->builder->positionAtEnd($check);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return $resultSlot;
    }
}
