<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * gethostbynamel() — IPv4 address list for hostname (ext/standard/dns.c parity, #3707).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/dns.c PHP_FUNCTION(gethostbynamel)
 */
final class gethostbynamel extends Internal
{
    public function __construct()
    {
        parent::__construct('gethostbynamel');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('gethostbynamel() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('gethostbynamel() requires a string hostname in this compiler build');
        }
        $result = VmDns::gethostbynamel($v->toString());
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('gethostbynamel() requires exactly one argument in this compiler build');
        }

        return JitGethostbynamel::invoke(
            $context,
            JitStringArg::lower($context, $args[0], 'gethostbynamel() hostname')
        );
    }
}
