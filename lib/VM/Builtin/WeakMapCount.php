<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\WeakRefSupport;

final class WeakMapCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('WeakMap::count() called without $this');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $receiver = WeakRefSupport::requireObject($frame->calledArgs[0], 'WeakMap');
        $map = $receiver->toObject();
        WeakRefSupport::purgeStaleMapEntries($map);
        $ht = WeakRefSupport::mapTable($map);
        if (null === $ht) {
            $frame->returnVar->int(0);

            return;
        }
        $frame->returnVar->int($ht->getNumElements());
    }
}
