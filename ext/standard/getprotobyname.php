<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** getprotobyname() — protocol entry array (VM host; JIT/AOT deferred, issue #3593). */
final class getprotobyname extends Internal
{
    public function __construct()
    {
        parent::__construct('getprotobyname');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('getprotobyname() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $nameVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('getprotobyname() requires a string name in this compiler build');
        }
        $result = VmNetwork::getprotobyname($nameVar->toString());
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($result));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('getprotobyname() is not implemented for JIT in this compiler build (issue #3593)');
    }
}
