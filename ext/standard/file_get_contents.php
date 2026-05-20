<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;
use PHPLLVM\Value;

/**
 * file_get_contents() — php://input reads REQUEST_BODY (VM only; issue #289).
 */
final class file_get_contents extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('file_get_contents() requires exactly one argument in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('file_get_contents() requires a string filename in this compiler build');
        }
        $filename = $v->toString();
        if ('php://input' === $filename) {
            $frame->returnVar->string(Superglobals::readRequestBody());

            return;
        }
        throw new \LogicException(
            'file_get_contents() only supports php://input in this compiler build'
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('file_get_contents() is not implemented for JIT in this compiler build');
    }
}
