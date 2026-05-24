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
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('WeakMap::offsetExists() expects object key');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $receiver = WeakRefSupport::requireObject($frame->calledArgs[0], 'WeakMap');
        $key = WeakRefSupport::objectKey($frame->calledArgs[1]);
        $ht = WeakRefSupport::mapTable($receiver->toObject());
        $keyVar = new Variable(Variable::TYPE_STRING);
        $keyVar->string($key);
        $frame->returnVar->bool($ht->keyExists($keyVar));
    }
}
