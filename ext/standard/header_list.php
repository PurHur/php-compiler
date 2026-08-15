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
 * Shared pending-header list implementation for headers_list() / apache_response_headers().
 *
 * Public symbol header_list() is absent from php-src (#28404) — do not register that name;
 * subclasses pass the Zend-facing name into the constructor.
 */
class header_list extends Internal
{
    public function __construct(?string $name = null)
    {
        parent::__construct($name ?? 'headers_list');
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
