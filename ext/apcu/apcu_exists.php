<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** apcu_exists() — PECL apcu / php-src ext/apcu (#6574). */
final class apcu_exists extends ApcuFunction
{
    public function __construct()
    {
        parent::__construct('apcu_exists');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'apcu_exists() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $keys = self::parseKeyOrKeyList($frame, 'apcu_exists', 0, 'keys');
        if (\is_array($keys)) {
            $out = new HashTable();
            foreach ($keys as $key) {
                if (VmApcu::exists($key)) {
                    $slot = new Variable();
                    $slot->bool(true);
                    $out->add($key, $slot);
                }
            }
            $frame->returnVar->array($out);

            return;
        }

        $frame->returnVar->bool(VmApcu::exists($keys));
    }
}
