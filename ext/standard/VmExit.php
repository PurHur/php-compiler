<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ScriptExit;
use PHPCompiler\VM\ShutdownQueue;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/** VM lowering for exit/die (issue #269). */
final class VmExit
{
    public static function terminate(?Variable $arg): never
    {
        $ctx = Superglobals::getActiveContext();
        if (null !== $ctx) {
            ShutdownQueue::run($ctx);
        }
        throw new ScriptExit(self::resolveStatus($arg));
    }

    public static function resolveStatus(?Variable $arg): int
    {
        if (null === $arg) {
            return 0;
        }
        $v = $arg->resolveIndirect();
        if (Variable::TYPE_STRING === $v->type) {
            echo $v->toString();

            return 0;
        }
        if (Variable::TYPE_INTEGER === $v->type) {
            return $v->toInt();
        }

        throw new \LogicException('exit() only supports string or integer status in this compiler build');
    }
}
