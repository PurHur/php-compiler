<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\spl\InternalIteratorBuiltin;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\WeakMapInternalIteratorHandler;
use PHPCompiler\VM\WeakRefSupport;

/**
 * WeakMap::getIterator() — InternalIterator over live entries (Zend/zend_weakrefs.c; #22267).
 */
final class WeakMapGetIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getIterator');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('WeakMap::getIterator() called without $this');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('WeakMap::getIterator() requires VM context');
        }
        $receiver = WeakRefSupport::requireObject($frame->calledArgs[0], 'WeakMap');
        $map = $receiver->toObject();
        $handler = WeakMapInternalIteratorHandler::forMap($map);
        $frame->returnVar->object(InternalIteratorBuiltin::fromLiveHandler($ctx, $handler));
    }
}
