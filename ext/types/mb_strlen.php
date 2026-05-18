<?php

declare(strict_types=1);

namespace PHPCompiler\ext\types;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * mb_strlen() — byte length of string (UTF-8 safe for ASCII subset in this compiler).
 */
final class mb_strlen extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strlen');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('mb_strlen() requires exactly one argument');
        }
        $var = $frame->calledArgs[0];
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmString::byteLength($var->resolveIndirect()->toString()));
        }
    }

    public Context $context;

    public function call(Context $context, Variable ...$args): Value
    {
        $this->context = $context;
        if (1 !== \count($args)) {
            throw new \LogicException('mb_strlen() requires exactly one argument');
        }
        $argValue = $context->helper->loadValue($args[0]);
        if (Variable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('mb_strlen() only supports strings in this compiler build');
        }
        $offset = $this->context->structFieldMap[$argValue->typeOf()->getElementType()->getName()]['length'];

        return $this->context->builder->load(
            $this->context->builder->structGep($argValue, $offset)
        );
    }
}
