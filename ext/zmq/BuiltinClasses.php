<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zmq;

use PHPCompiler\VM\Context;

/** Register ext/zmq builtin classes (php/pecl-networking-zmq; #6443). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmZmq::registerClasses($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }
}
