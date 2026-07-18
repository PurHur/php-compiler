<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/** apcu_store() — PECL apcu / php-src ext/apcu (#6574). */
final class apcu_store extends ApcuFunction
{
    public function __construct()
    {
        parent::__construct('apcu_store');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'apcu_store() expects between 1 and 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $first = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY === $first->type) {
            // Multi-store: apcu_store(['k' => $v, ...], null, $ttl)
            $ttl = self::parseOptionalTtl($frame, 'apcu_store', 2);
            $results = new HashTable();
            foreach ($first->toArray()->iterateKeyed(true) as [$keyVar, $value]) {
                $keyStr = VmString::coerceZparamStrBuiltinArg($keyVar, 'apcu_store', 1, 'key');
                $ok = VmApcu::store($keyStr, $value, $ttl);
                $slot = new Variable();
                $slot->bool($ok);
                $results->add($keyStr, $slot);
            }
            $frame->returnVar->array($results);

            return;
        }

        if ($argc < 2) {
            throw new \ArgumentCountError(
                'apcu_store() expects at least 2 arguments when key is a string, '.$argc.' given'
            );
        }

        $key = VmString::coerceZparamStrBuiltinArg($frame->calledArgs[0], 'apcu_store', 1, 'key');
        $ttl = self::parseOptionalTtl($frame, 'apcu_store', 2);
        $ok = VmApcu::store($key, $frame->calledArgs[1], $ttl);
        $frame->returnVar->bool($ok);
    }
}
