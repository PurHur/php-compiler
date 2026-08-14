<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
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
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('WeakMap::offsetExists() called without $this');
        }
        // php-src Zend/zend_weakrefs.stub.php — offsetExists(object $object): bool (#30909)
        $this->requireExactUserArgCount($frame, 'WeakMap::offsetExists', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $receiver = WeakRefSupport::requireObject($frame->calledArgs[0], 'WeakMap');
        WeakRefSupport::requireWeakMapKey($frame->calledArgs[1]);
        $map = $receiver->toObject();
        WeakRefSupport::purgeStaleMapEntries($map);
        if (!WeakRefSupport::isTargetAlive($frame->calledArgs[1])) {
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
        $frame->returnVar->bool($ht->offsetIsSet($keyVar));
    }
}
