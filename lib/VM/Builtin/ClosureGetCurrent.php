<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * Closure::getCurrent(): Closure — PHP 8.5+ active closure introspection
 * (Zend/zend_closures.stub.php, #22583, #28710).
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
        $owner = self::resolveExecutingClosureObject($frame);
        if (null === $owner) {
            $frame->returnVar->null();

            return;
        }
        $ret = new Variable(Variable::TYPE_OBJECT);
        $ret->object($owner);
        $frame->returnVar->copyFrom($ret);
    }

    /**
     * Zend zend_closures.c Closure::getCurrent — immediate caller must be a closure body (#16775).
     */
    private static function resolveExecutingClosureObject(Frame $handlerFrame): ?\PHPCompiler\VM\ObjectEntry
    {
        $caller = $handlerFrame->parent;
        if (null === $caller || null === $caller->block?->func) {
            return null;
        }
        if ((($caller->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) === 0) {
            return null;
        }
        $state = $caller->closureCall;
        if (null === $state) {
            return null;
        }

        return $state->ownerObject;
    }
}
