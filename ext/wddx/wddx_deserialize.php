<?php

declare(strict_types=1);

namespace PHPCompiler\ext\wddx;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** wddx_deserialize() — parse WDDX packet (php-src ext/wddx/wddx.c; #6327). */
final class wddx_deserialize extends WddxFunction
{
    public function __construct()
    {
        parent::__construct('wddx_deserialize');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'wddx_deserialize', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $packet = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $packet->type) {
            if (null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    \E_WARNING,
                    'wddx_deserialize(): Expecting parameter 1 to be a string or a stream',
                    $frame->file ?? '',
                    $frame->line ?? 0
                );
            }

            return;
        }
        $decoded = VmWddx::deserialize($packet->toString(null));
        if (false === $decoded) {
            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($decoded));
    }
}
