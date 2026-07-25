<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** apcu_key_info() — PECL apcu per-key cache stats (#22253). */
final class apcu_key_info extends ApcuFunction
{
    public function __construct()
    {
        parent::__construct('apcu_key_info');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'apcu_key_info() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $key = self::parseKey($frame, 'apcu_key_info', 0, 'key');
        $info = VmApcu::keyInfo($key);
        if (null === $info) {
            $frame->returnVar->null();

            return;
        }

        $ht = new HashTable();
        foreach ($info as $k => $v) {
            $slot = new Variable();
            $slot->int((int) $v);
            $ht->add((string) $k, $slot);
        }
        $frame->returnVar->array($ht);
    }
}
