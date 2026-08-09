<?php

declare(strict_types=1);

namespace PHPCompiler\ext\rar;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** rar_allow_broken_set() — store allow-broken flag (PECL rar rararch.c; #27878). */
final class rar_allow_broken_set extends RarProceduralFunction
{
    public function __construct()
    {
        parent::__construct('rar_allow_broken_set');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'rar_allow_broken_set', 2);
        if (null === $frame->returnVar) {
            return;
        }
        try {
            $archive = VmRar::requireArchive($frame->calledArgs[0], 'rar_allow_broken_set()');
            $flag = $frame->calledArgs[1]->resolveIndirect();
            $allow = match ($flag->type) {
                Variable::TYPE_BOOLEAN => $flag->toBool(null),
                Variable::TYPE_INTEGER => 0 !== $flag->toInt(null),
                Variable::TYPE_NULL => false,
                default => (bool) VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[1],
                    'rar_allow_broken_set',
                    1,
                    'allow_broken'
                ),
            };
            $frame->returnVar->bool(VmRar::setAllowBroken($archive, $allow));
        } catch (\RarException|\TypeError) {
            $frame->returnVar->bool(false);
        }
    }
}
