<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * Closure::getCurrent(): Closure — PHP 8.5+ recursive closure introspection (Zend/zend_closures.c).
 */
final class ClosureGetCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCurrent');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $caller = $frame->parent;
        if (null === $caller) {
            throw new \Error('Current function is not a closure');
        }
        $state = $caller->closureCall;
        if (null === $state) {
            throw new \Error('Current function is not a closure');
        }
        $owner = $state->ownerObject;
        if (null === $owner) {
            throw new \Error('Current function is not a closure');
        }
        $ret = new Variable(Variable::TYPE_OBJECT);
        $ret->object($owner);
        $frame->returnVar->copyFrom($ret);
    }
}
