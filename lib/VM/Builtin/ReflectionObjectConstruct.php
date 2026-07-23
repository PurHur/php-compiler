<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/**
 * ReflectionObject::__construct(object $object) — VM (#20098).
 *
 * php-src: ext/reflection/php_reflection.c — zim_ReflectionObject___construct
 */
final class ReflectionObjectConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs) - 1;
        if ($argc !== 1) {
            ReflectionSupport::throwConstructArgumentCountError('ReflectionObject', 1, $argc);
        }
        $objectArg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectArg->type) {
            throw new \TypeError(
                'ReflectionObject::__construct(): Argument #1 ($object) must be of type object, '
                .EnumCaseSupport::typeNameForVariable($objectArg).' given'
            );
        }
        $target = $objectArg->toObject();
        $receiver = ReflectionSupport::requireReflectionObject($frame, $frame->calledArgs[0]);
        $receiver->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($target->class->name);
        $wrapped = new Variable();
        $wrapped->object($target);
        $receiver->getProperty(ReflectionSupport::PROP_OBJECT_TARGET)->copyFrom($wrapped);
        $receiver->constructed = true;
        // Do not touch returnVar: it may alias the `new ReflectionObject()` result slot (#1885, #4598).
    }
}
