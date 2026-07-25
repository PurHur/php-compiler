<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmCallable;

/** apcu_entry() — PECL apcu fetch-or-generate via callback (#22253). */
final class apcu_entry extends ApcuFunction
{
    public function __construct()
    {
        parent::__construct('apcu_entry');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'apcu_entry() expects between 2 and 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $key = self::parseKey($frame, 'apcu_entry', 0, 'key');
        $callback = $frame->calledArgs[1]->resolveIndirect();
        $ctx = $frame->vmContext;
        if (!VmCallable::isCallable($ctx, $callback, false, null, $frame)) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError('apcu_entry'));
        }

        $success = false;
        $existing = VmApcu::fetch($key, $success);
        if ($success && null !== $existing) {
            $frame->returnVar->copyFrom($existing);

            return;
        }

        $keyArg = new Variable();
        $keyArg->string($key);
        $generated = VmCallable::invokeAs('apcu_entry', $ctx, $callback, $keyArg);
        $ttl = self::parseOptionalTtl($frame, 'apcu_entry', 2);
        VmApcu::store($key, $generated, $ttl);
        $frame->returnVar->copyFrom($generated);
    }
}
