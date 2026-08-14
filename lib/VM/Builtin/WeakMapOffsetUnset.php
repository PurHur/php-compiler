<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\WeakRefRegistry;
use PHPCompiler\VM\WeakRefSupport;

final class WeakMapOffsetUnset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetUnset');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('WeakMap::offsetUnset() called without $this');
        }
        // php-src Zend/zend_weakrefs.stub.php — offsetUnset(object $object): void (#30909)
        $this->requireExactUserArgCount($frame, 'WeakMap::offsetUnset', 1);
        $receiver = WeakRefSupport::requireObject($frame->calledArgs[0], 'WeakMap');
        $targetId = WeakRefSupport::targetObjectId($frame->calledArgs[1]);
        $key = WeakRefSupport::objectKey($frame->calledArgs[1]);
        $ht = WeakRefSupport::mapTable($receiver->toObject());
        if (null === $ht) {
            return;
        }
        $keyVar = new Variable(Variable::TYPE_STRING);
        $keyVar->string($key);
        $ht->offsetUnset($keyVar);
        WeakRefRegistry::unregisterWeakMapEntry($targetId, $receiver->toObject()->id, $key);
    }
}
