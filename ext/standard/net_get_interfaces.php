<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * net_get_interfaces() — local network interface enumeration (#6106).
 *
 * php-src: ext/standard/net.c — PHP_FUNCTION(net_get_interfaces)
 */
final class net_get_interfaces extends Internal
{
    public function __construct()
    {
        parent::__construct('net_get_interfaces');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError(
                'net_get_interfaces() expects exactly 0 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ifaces = VmNetInterfaces::get();
        if (false === $ifaces) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($ifaces);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'net_get_interfaces() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitNetGetInterfaces::invoke($context);
    }
}
