<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\Frame;

/** apcu_inc() — PECL apcu atomic increment (#22253). */
final class apcu_inc extends ApcuFunction
{
    public function __construct()
    {
        parent::__construct('apcu_inc');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(
                'apcu_inc() expects between 1 and 4 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $key = self::parseKey($frame, 'apcu_inc', 0, 'key');
        $step = 1;
        if (isset($frame->calledArgs[1])) {
            $step = \PHPCompiler\ext\standard\VmMath::parseIntBuiltinArgForFrame(
                $frame,
                1,
                'apcu_inc',
                2,
                'step'
            );
        }
        $ttl = self::parseOptionalTtl($frame, 'apcu_inc', 3);
        $result = VmApcu::adjust($key, $step, $ttl);
        $ok = false !== $result;
        if (isset($frame->calledArgs[2])) {
            $frame->calledArgs[2]->byRefTarget()->bool($ok);
        }
        if (!$ok) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int((int) $result);
    }
}
