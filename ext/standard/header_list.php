<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\Web\ResponseContext;
use PHPLLVM\Value;

/**
 * header_list() — list pending response headers (issue #311).
 */
class header_list extends Internal
{
    public function __construct(?string $name = null)
    {
        parent::__construct($name ?? 'header_list');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError($this->getName().'() expects exactly 0 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmFs::stringListToArray(ResponseContext::listHeaders()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $this->getName().'() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitPendingHeaders::list($context);
    }
}
