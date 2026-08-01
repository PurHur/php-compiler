<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

use PHPCompiler\Frame;

/** Memcached::__construct(?string $persistent_id = null) — initialize internal state (#6099). */
final class MemcachedConstruct extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Memcached::__construct() called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('Memcached::__construct() must be called on Memcached');
        }
        VmMemcached::initObject($var->toObject());
    }
}
