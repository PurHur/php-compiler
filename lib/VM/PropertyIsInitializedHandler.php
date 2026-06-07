<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\PropertyIsInitializedHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * VM handler for object::propertyIsInitialized() (#6513, Zend zend_object_handlers.c).
 */
final class PropertyIsInitializedHandler extends Internal
{
    public function __construct()
    {
        parent::__construct('propertyIsInitialized');
    }

    public function execute(Frame $frame): void
    {
        $vm = \PHPCompiler\VM::running();
        if (null === $vm) {
            throw new \LogicException('propertyIsInitialized() requires an active VM');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('propertyIsInitialized() expects exactly 1 argument, 0 given');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('propertyIsInitialized() must be called on an object');
        }
        $propName = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'propertyIsInitialized',
            0,
            'name'
        );
        $object = $receiver->toObject();
        try {
            $scopeFrame = $frame->parent ?? $frame;
            $initialized = PropertyInit::isInstancePropertyInitialized($vm, $object, $propName, $scopeFrame);
        } catch (\LogicException $e) {
            throw new \Error($e->getMessage());
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($initialized);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \ArgumentCountError('propertyIsInitialized() expects exactly 1 argument, 0 given');
        }
        if (Variable::TYPE_OBJECT !== $args[0]->type) {
            throw new \LogicException('propertyIsInitialized() must be called on an object');
        }

        return PropertyIsInitializedHelper::lower($context, $args[0], $args[1]);
    }
}
