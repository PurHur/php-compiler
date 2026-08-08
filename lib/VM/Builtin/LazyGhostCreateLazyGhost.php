<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\Variable;

/** Synthetic Class::createLazyGhost() for LazyGhostTrait classes (#6531). */
final class LazyGhostCreateLazyGhost extends VmClassMethod
{
    public function __construct(private ClassEntry $classEntry)
    {
        parent::__construct('createLazyGhost');
    }

    public function execute(Frame $frame): void
    {
        $classEntry = $this->resolveTargetClass($frame);
        $initClosure = null;
        $initClosureObject = null;
        if (\count($frame->calledArgs) >= 1) {
            $initVar = $frame->calledArgs[0]->resolveIndirect();
            if (!$initVar->isUndefined() && Variable::TYPE_NULL !== $initVar->type) {
                if (Variable::TYPE_OBJECT !== $initVar->type) {
                    throw new \TypeError(
                        'Class::createLazyGhost(): Argument #1 ($initializer) must be of type ?callable, '
                        .EnumCaseSupport::typeNameForVariable($initVar).' given'
                    );
                }
                $initObject = $initVar->toObject();
                if (null === $initObject->closureState) {
                    throw new \TypeError(
                        'Class::createLazyGhost(): Argument #1 ($initializer) must be of type ?callable, '
                        .$initObject->class->name.' given'
                    );
                }
                $initClosure = $initObject->closureState;
                $initClosureObject = $initObject;
            }
        }

        $lazy = LazyObjectSupport::createGhost($classEntry, $initClosure, 0, $initClosureObject);
        if (\count($frame->calledArgs) >= 2) {
            $propsVar = $frame->calledArgs[1]->resolveIndirect();
            if (!$propsVar->isUndefined() && Variable::TYPE_NULL !== $propsVar->type) {
                if (Variable::TYPE_ARRAY !== $propsVar->type) {
                    throw new \TypeError(
                        'Class::createLazyGhost(): Argument #2 ($instanceProperties) must be of type ?array, '
                        .EnumCaseSupport::typeNameForVariable($propsVar).' given'
                    );
                }
                LazyObjectSupport::applyInstanceProperties($lazy, $propsVar->toArray());
            }
        }

        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object($lazy);
            $frame->returnVar->copyFrom($out);
        }
    }

    private function resolveTargetClass(Frame $frame): ClassEntry
    {
        $vm = \PHPCompiler\VM::running();
        if (null !== $vm && null !== $frame->calledClass && '' !== $frame->calledClass) {
            $lc = strtolower($frame->calledClass);
            if (isset($vm->context->classes[$lc])) {
                return $vm->context->classes[$lc];
            }
        }

        return $this->classEntry;
    }
}
