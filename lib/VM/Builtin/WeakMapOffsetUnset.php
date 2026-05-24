<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\WeakRefSupport;

final class WeakMapOffsetUnset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetUnset');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('WeakMap::offsetUnset() expects object key');
        }
        $receiver = WeakRefSupport::requireObject($frame->calledArgs[0], 'WeakMap');
        $key = WeakRefSupport::objectKey($frame->calledArgs[1]);
        $ht = WeakRefSupport::mapTable($receiver->toObject());
        $keyVar = new Variable(Variable::TYPE_STRING);
        $keyVar->string($key);
        $ht->offsetUnset($keyVar);
    }
}
