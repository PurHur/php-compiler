<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/** apcu_add() — PECL apcu exclusive store (#22253). */
final class apcu_add extends ApcuFunction
{
    public function __construct()
    {
        parent::__construct('apcu_add');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'apcu_add() expects between 1 and 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $first = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY === $first->type) {
            // Multi-add: return only failed keys mapped to -1 (PECL apc_store_helper exclusive).
            $ttl = self::parseOptionalTtl($frame, 'apcu_add', 2);
            $failed = new HashTable();
            foreach ($first->toArray()->iterateKeyed(true) as [$keyVar, $value]) {
                $keyStr = VmString::coerceZparamStrBuiltinArg($keyVar, 'apcu_add', 1, 'key');
                if (!VmApcu::add($keyStr, $value, $ttl)) {
                    $slot = new Variable();
                    $slot->int(-1);
                    $failed->add($keyStr, $slot);
                }
            }
            $frame->returnVar->array($failed);

            return;
        }

        if ($argc < 2) {
            throw new \ArgumentCountError(
                'apcu_add() expects at least 2 arguments when key is a string, '.$argc.' given'
            );
        }

        $key = VmString::coerceZparamStrBuiltinArg($frame->calledArgs[0], 'apcu_add', 1, 'key');
        $ttl = self::parseOptionalTtl($frame, 'apcu_add', 2);
        $ok = VmApcu::add($key, $frame->calledArgs[1], $ttl);
        $frame->returnVar->bool($ok);
    }
}
