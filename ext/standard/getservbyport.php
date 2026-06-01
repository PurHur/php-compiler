<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * getservbyport() — service name by port (VM host; JIT/AOT via libc, issue #3650).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/network.c PHP_FUNCTION(getservbyport)
 */
final class getservbyport extends Internal
{
    public function __construct()
    {
        parent::__construct('getservbyport');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('getservbyport() requires exactly two arguments in this compiler build');
        }
        $portVar = $frame->calledArgs[0]->resolveIndirect();
        $protoVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $portVar->type) {
            throw new \LogicException('getservbyport() port must be an integer in this compiler build');
        }
        if (Variable::TYPE_STRING !== $protoVar->type) {
            throw new \LogicException('getservbyport() protocol must be a string in this compiler build');
        }
        $name = VmNetworkServices::getservbyport($portVar->toInt(), $protoVar->toString());
        if (false === $name) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($name);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('getservbyport() requires exactly two arguments in this compiler build');
        }

        return JitNetworkServices::getservbyport($context, $args[0], $args[1]);
    }
}
