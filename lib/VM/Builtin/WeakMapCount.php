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
        // php-src Zend/zend_weakrefs.stub.php — count(): int (#31129)
        $this->requireExactUserArgCount($frame, 'WeakMap::count', 0);
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
        // Count live entries only — same filter as WeakMapIterator (#19369).
        // getNumElements() can still include tombstones/stale keys if purge races materialization.
        $live = 0;
        foreach ($ht->iterateKeyed() as $pair) {
            [$storedKeyVar] = $pair;
            if (WeakRefSupport::isLiveMapKey($storedKeyVar)) {
                ++$live;
            }
        }
        $frame->returnVar->int($live);
    }
}
