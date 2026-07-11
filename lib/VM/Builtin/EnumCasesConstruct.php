<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/** EnumCases::__construct(string $name) — VM (#13057, Zend/zend_attributes.c). */
final class EnumCasesConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('EnumCases::__construct() called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('EnumCases::__construct() called without $this');
        }
        $object = $receiver->toObject();
        if (!isset($frame->calledArgs[1])) {
            throw new \ArgumentCountError('EnumCases::__construct() expects exactly 1 argument, 0 given');
        }
        $value = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $value->type) {
            throw new \TypeError(
                'EnumCases::__construct(): Argument #1 ($name) must be of type string, '
                .EnumCaseSupport::typeNameForVariable($value).' given'
            );
        }
        $prop = $object->getProperty('name');
        $prop->string($value->toString());
    }
}
