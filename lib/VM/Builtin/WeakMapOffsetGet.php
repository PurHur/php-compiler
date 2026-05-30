<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ObjectRegistry;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\WeakRefSupport;

final class WeakMapOffsetGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetGet');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('WeakMap::offsetGet() expects object key');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $receiver = WeakRefSupport::requireObject($frame->calledArgs[0], 'WeakMap');
        $map = $receiver->toObject();
        WeakRefSupport::purgeStaleMapEntries($map);
        $targetId = WeakRefSupport::targetObjectId($frame->calledArgs[1]);
        if (!ObjectRegistry::isRegistered($targetId)) {
            $frame->returnVar->null();

            return;
        }
        $key = WeakRefSupport::objectKey($frame->calledArgs[1]);
        $ht = WeakRefSupport::mapTable($map);
        if (null === $ht) {
            $frame->returnVar->null();

            return;
        }
        $keyVar = new Variable(Variable::TYPE_STRING);
        $keyVar->string($key);
        if (!$ht->keyExists($keyVar)) {
            $frame->returnVar->null();

            return;
        }
        $slot = $ht->findVariable($keyVar, false);
        if (null === $slot) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->copyFrom($slot);
    }
}
