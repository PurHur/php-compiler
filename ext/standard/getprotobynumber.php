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
 * getprotobynumber() — protocol name by number (VM host; JIT/AOT via libc, issue #3650).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/network.c PHP_FUNCTION(getprotobynumber)
 */
final class getprotobynumber extends Internal
{
    public function __construct()
    {
        parent::__construct('getprotobynumber');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('getprotobynumber() requires exactly one argument in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $v->type) {
            throw new \LogicException('getprotobynumber() requires an integer in this compiler build');
        }
        $name = VmNetworkServices::getprotobynumber($v->toInt());
        if (false === $name) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($name);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('getprotobynumber() requires exactly one argument in this compiler build');
        }

        return JitNetworkServices::getprotobynumber($context, $args[0]);
    }
}
