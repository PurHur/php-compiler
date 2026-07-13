<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VariableObject;

/**
 * DOM instance method dispatch for JIT/AOT helpers (#17130, #17391).
 *
 * Per-arity entrypoints avoid args-table unpacking in standalone AOT helper TUs.
 */
final class VmDomInstanceInvoke
{
    public static function invoke0Object(Variable $receiver, string $methodLc): Variable
    {
        return self::dispatch($receiver, $methodLc);
    }

    public static function invoke1Object(Variable $receiver, string $methodLc, Variable $a1): Variable
    {
        return self::dispatch($receiver, $methodLc, $a1);
    }

    public static function invoke2Object(
        Variable $receiver,
        string $methodLc,
        Variable $a1,
        Variable $a2
    ): Variable {
        return self::dispatch($receiver, $methodLc, $a1, $a2);
    }

    public static function invoke3Object(
        Variable $receiver,
        string $methodLc,
        Variable $a1,
        Variable $a2,
        Variable $a3
    ): Variable {
        return self::dispatch($receiver, $methodLc, $a1, $a2, $a3);
    }

    public static function invoke4Object(
        Variable $receiver,
        string $methodLc,
        Variable $a1,
        Variable $a2,
        Variable $a3,
        Variable $a4
    ): Variable {
        return self::dispatch($receiver, $methodLc, $a1, $a2, $a3, $a4);
    }

    private static function dispatch(Variable $receiver, string $methodLc, Variable ...$extra): Variable
    {
        $self = VariableObject::entry($receiver->resolveIndirect());
        $ctx = VmDomJitFrame::vmContext();
        $methodLc = strtolower($methodLc);

        return match ($methodLc) {
            'createelement' => VmDomJitDispatch::createElement($ctx, $self, $extra),
            'loadhtml' => VmDomJitDispatch::loadHTML($ctx, $self, $extra),
            'getelementbyid' => VmDomJitDispatch::getElementById($self, $extra),
            'appendchild' => VmDomJitDispatch::appendChild($ctx, $self, $extra),
            'setattribute' => VmDomJitDispatch::setAttribute($ctx, $self, $extra),
            'add' => VmDomJitDispatch::tokenListAdd($ctx, $self, $extra),
            'remove' => VmDomJitDispatch::tokenListRemove($ctx, $self, $extra),
            'contains' => VmDomJitDispatch::tokenListContains($self, $extra),
            'item' => VmDomJitDispatch::dispatchItem($ctx, $self, $extra),
            'toggle' => VmDomJitDispatch::tokenListToggle($ctx, $self, $extra),
            'query' => VmDomJitDispatch::xpathQuery($ctx, $self, $extra),
            'evaluate' => VmDomJitDispatch::xpathEvaluate($ctx, $self, $extra),
            'registernamespace' => VmDomJitDispatch::xpathRegisterNamespace($self, $extra),
            'comparedocumentposition' => VmDomJitDispatch::compareDocumentPosition($self, $extra),
            default => throw new \Error('Call to undefined method '.$self->class->name.'::'.$methodLc.'()'),
        };
    }
}
