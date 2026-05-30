<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\WeakRefRegistry;
use PHPCompiler\VM\WeakRefSupport;

final class WeakMapOffsetSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetSet');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('WeakMap::offsetSet() expects object key and value');
        }
        $receiver = WeakRefSupport::requireObject($frame->calledArgs[0], 'WeakMap');
        $targetId = WeakRefSupport::targetObjectId($frame->calledArgs[1]);
        $key = WeakRefSupport::objectKey($frame->calledArgs[1]);
        $mapObject = $receiver->toObject();
        WeakRefRegistry::registerWeakMapEntry($targetId, $mapObject, $key);
        $ht = WeakRefSupport::mapTable($mapObject);
        if (null === $ht) {
            throw new \LogicException('WeakMap backing store is missing in this compiler build');
        }
        $ht->add($key, $frame->calledArgs[2]);
        WeakRefSupport::trackWeakMapKey($mapObject, $frame->calledArgs[1]);
    }
}
