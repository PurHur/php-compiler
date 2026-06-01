<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** getservbyname() — service entry array (VM host; JIT/AOT deferred, issue #3593). */
final class getservbyname extends Internal
{
    public function __construct()
    {
        parent::__construct('getservbyname');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('getservbyname() requires exactly two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $serviceVar = $frame->calledArgs[0]->resolveIndirect();
        $protocolVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $serviceVar->type || Variable::TYPE_STRING !== $protocolVar->type) {
            throw new \LogicException('getservbyname() requires string service and protocol in this compiler build');
        }
        $result = VmNetwork::getservbyname($serviceVar->toString(), $protocolVar->toString());
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($result));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('getservbyname() is not implemented for JIT in this compiler build (issue #3593)');
    }
}
