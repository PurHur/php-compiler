<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
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
        WeakRefSupport::requireWeakMapKey($frame->calledArgs[1]);
        $map = $receiver->toObject();
        WeakRefSupport::purgeStaleMapEntries($map);
        if (!WeakRefSupport::isTargetAlive($frame->calledArgs[1])) {
            $frame->returnVar->null();

            return;
        }
        $key = WeakRefSupport::objectKey($frame->calledArgs[1]);
        $ht = WeakRefSupport::mapTable($map);
        if (null === $ht) {
            // Zend zend_weakmap_offset_get — missing key throws Error (#24771).
            WeakRefSupport::throwMissingKeyError($frame->calledArgs[1]);
        }
        $keyVar = new Variable(Variable::TYPE_STRING);
        $keyVar->string($key);
        if (!$ht->keyExists($keyVar)) {
            WeakRefSupport::throwMissingKeyError($frame->calledArgs[1]);
        }
        $slot = $ht->findVariable($keyVar, false);
        if (null === $slot) {
            WeakRefSupport::throwMissingKeyError($frame->calledArgs[1]);
        }
        $frame->returnVar->copyFrom($slot);
    }
}
