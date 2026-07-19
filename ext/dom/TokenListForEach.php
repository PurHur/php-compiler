<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\Frame;

/** Dom\TokenList::forEach() — VM (#20884). */
final class TokenListForEach extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('forEach');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_TOKEN_LIST, 'DOMTokenList::forEach()');
        $argc = \count($frame->calledArgs);
        // calledArgs[0] = $this; need callback at [1]
        if ($argc < 2) {
            throw new \ArgumentCountError(sprintf(
                'Dom\\TokenList::forEach() expects at least 1 argument, %d given',
                $argc - 1
            ));
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMTokenList::forEach() requires VM context');
        }
        $callback = $frame->calledArgs[1]->resolveIndirect();
        if (!VmCallable::isCallable($frame->vmContext, $callback)) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError('Dom\\TokenList::forEach'));
        }
        // Optional $thisArg at [2] — accepted for arity; not used as JS this-binding.
        VmDomTokenList::forEachTokens($frame->vmContext, $receiver, $callback);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
