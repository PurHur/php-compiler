<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmStreamArg;

/** apcu_cache_info() — PECL apcu / php-src ext/apcu (#6574). */
final class apcu_cache_info extends ApcuFunction
{
    public function __construct()
    {
        parent::__construct('apcu_cache_info');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'apcu_cache_info() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $limited = false;
        if (1 === $argc) {
            $var = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN === $var->type) {
                $limited = $var->toBool();
            } elseif (Variable::TYPE_INTEGER === $var->type) {
                $limited = 0 !== $var->toInt();
            } elseif (Variable::TYPE_NULL !== $var->type) {
                throw new \TypeError(\sprintf(
                    'apcu_cache_info(): Argument #1 ($limited) must be of type bool, %s given',
                    VmStreamArg::debugTypeName($var)
                ));
            }
        }

        $frame->returnVar->copyFrom(self::importCacheInfo(VmApcu::cacheInfo($limited)));
    }
}
