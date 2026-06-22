<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * gethostbynamel() — IPv4 address list for hostname (ext/standard/dns.c parity, #3707).
 *
 * VM: VmDns (libc FFI, #4928). JIT/AOT: GethostbynamelRuntime → GethostbynamelJitHelper PHP (#9382).
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
        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'gethostbynamel', 0, 'hostname');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmDns::gethostbynamel($hostname);
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
            JitStringBuiltinArg::lower($context, $args[0], 'gethostbynamel', 0, 'hostname')
        );
    }
}
