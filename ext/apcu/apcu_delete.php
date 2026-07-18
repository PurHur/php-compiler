<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** apcu_delete() — PECL apcu / php-src ext/apcu (#6574). */
final class apcu_delete extends ApcuFunction
{
    public function __construct()
    {
        parent::__construct('apcu_delete');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'apcu_delete() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $keys = self::parseKeyOrKeyList($frame, 'apcu_delete', 0, 'key');
        if (\is_array($keys)) {
            $out = new HashTable();
            foreach ($keys as $key) {
                $slot = new Variable();
                $slot->bool(VmApcu::delete($key));
                $out->add($key, $slot);
            }
            $frame->returnVar->array($out);

            return;
        }

        $frame->returnVar->bool(VmApcu::delete($keys));
    }
}
