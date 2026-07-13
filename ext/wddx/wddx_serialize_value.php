<?php

declare(strict_types=1);

namespace PHPCompiler\ext\wddx;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** wddx_serialize_value() — single-value WDDX packet (php-src ext/wddx/wddx.c; #6327). */
final class wddx_serialize_value extends WddxFunction
{
    public function __construct()
    {
        parent::__construct('wddx_serialize_value');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'wddx_serialize_value', 1);
        $this->requireAtMostArgCount($frame, 'wddx_serialize_value', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $comment = null;
        if (isset($frame->calledArgs[1])) {
            $comment = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'wddx_serialize_value',
                1,
                'comment'
            );
        }
        try {
            $xml = VmWddx::serializeValue($frame->calledArgs[0], $comment);
        } catch (\Error $e) {
            throw $e;
        }
        $frame->returnVar->string($xml);
    }
}
