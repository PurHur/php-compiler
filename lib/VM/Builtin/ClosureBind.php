<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\Variable;

/** Closure::bind() — static VM (#3673, Zend zend_closures.c). */
final class ClosureBind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('bind');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('Closure::bind() expects at least 2 arguments');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('Closure::bind() requires VM context');
        }
        $callable = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $callable->type) {
            throw new \TypeError(
                'Closure::bind(): Argument #1 ($closure) must be of type Closure, '
                .self::valueTypeName($callable).' given'
            );
        }
        $object = $callable->toObject();
        if (null === $object->closureState) {
            throw new \TypeError(
                'Closure::bind(): Argument #1 ($closure) must be of type Closure, '
                .$object->class->name.' given'
            );
        }
        $newScope = $frame->calledArgs[2] ?? null;
        $bound = ClosureSupport::bindTo(
            $frame->vmContext,
            $object->closureState,
            $frame->calledArgs[1],
            $newScope,
            'Closure::bind()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $bound) {
            $frame->returnVar->null();

            return;
        }
        $ret = new Variable(Variable::TYPE_OBJECT);
        $ret->object($bound);
        $frame->returnVar->copyFrom($ret);
    }

    private static function valueTypeName(Variable $value): string
    {
        return match ($value->type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}
