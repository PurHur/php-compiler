<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * VM handler for `new Class(...)` first-class callables (#9767, zend_compile.c).
 */
final class NewCallableHandler extends Internal
{
    public function __construct(
        private ClassEntry $class,
    ) {
        parent::__construct('new '.$class->name);
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('new(...) callable requires active VM context');
        }
        $object = $ctx->runtime->vm->instantiateFromNewCallable(
            $this->class,
            $frame,
            ...$frame->calledArgs
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($object);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('new(...) first-class callable is not supported in JIT in this compiler build');
    }
}
