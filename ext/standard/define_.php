<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** define() — register a user constant at runtime (issue #204). */
final class define_ extends Internal
{
    public function __construct()
    {
        parent::__construct('define');
    }

    public function execute(Frame $frame): void
    {
        if (count($frame->calledArgs) < 2) {
            throw new \LogicException('define() requires at least two arguments');
        }
        $nameVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('define() constant name must be a string');
        }
        $value = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->vmContext) {
            throw new \LogicException('define() requires VM context');
        }
        $ok = $frame->vmContext->defineConstant($nameVar->toString(), $value);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) >= 1 && (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type)) {
            $this->jitString($context, $args[0], 'define() constant name');
        }
        throw new \LogicException(
            'define() is not implemented for JIT; use literal name and value (folded at compile time)'
        );
    }
}
