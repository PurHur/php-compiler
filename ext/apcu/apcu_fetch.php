<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** apcu_fetch() — PECL apcu / php-src ext/apcu (#6574). */
final class apcu_fetch extends ApcuFunction
{
    public function __construct()
    {
        parent::__construct('apcu_fetch');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'apcu_fetch() expects between 1 and 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $keys = self::parseKeyOrKeyList($frame, 'apcu_fetch', 0, 'key');
        $hasSuccess = isset($frame->calledArgs[1]);

        if (\is_array($keys)) {
            $out = new HashTable();
            $any = false;
            foreach ($keys as $key) {
                $success = false;
                $value = VmApcu::fetch($key, $success);
                if ($success && null !== $value) {
                    $any = true;
                    $out->add($key, $value);
                }
            }
            if ($hasSuccess) {
                $frame->calledArgs[1]->byRefTarget()->bool($any);
            }
            $frame->returnVar->array($out);

            return;
        }

        $success = false;
        $value = VmApcu::fetch($keys, $success);
        if ($hasSuccess) {
            $frame->calledArgs[1]->byRefTarget()->bool($success);
        }
        if (!$success || null === $value) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($value);
    }
}
