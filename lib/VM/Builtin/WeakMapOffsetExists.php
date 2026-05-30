<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ObjectRegistry;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\WeakRefSupport;

final class WeakMapOffsetExists extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetExists');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('WeakMap::offsetExists() expects object key');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $receiver = WeakRefSupport::requireObject($frame->calledArgs[0], 'WeakMap');
        $map = $receiver->toObject();
        WeakRefSupport::purgeStaleMapEntries($map);
        $targetId = WeakRefSupport::targetObjectId($frame->calledArgs[1]);
        if (!ObjectRegistry::isRegistered($targetId)) {
            $frame->returnVar->bool(false);

            return;
        }
        $key = WeakRefSupport::objectKey($frame->calledArgs[1]);
        $ht = WeakRefSupport::mapTable($map);
        if (null === $ht) {
            $frame->returnVar->bool(false);

            return;
        }
        $keyVar = new Variable(Variable::TYPE_STRING);
        $keyVar->string($key);
        $frame->returnVar->bool($ht->keyExists($keyVar));
    }
}
