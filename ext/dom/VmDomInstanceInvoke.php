<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VariableObject;

/**
 * DOM instance method dispatch for JIT/AOT helpers (#17130).
 *
 * Keep this file to a single compiled entrypoint; bodies live in VmDomJitDispatch.
 */
final class VmDomInstanceInvoke
{
    public static function invokeArgv(Variable $receiver, string $methodLc, Variable $argsTable): Variable
    {
        $ctx = VmDomJitFrame::vmContext();
        $extra = VmDomJitDispatch::unpackArgs($argsTable);
        $self = VariableObject::entry($receiver);
        $methodLc = strtolower($methodLc);

        return match ($methodLc) {
            'createelement' => VmDomJitDispatch::createElement($ctx, $self, $extra),
            'appendchild' => VmDomJitDispatch::appendChild($ctx, $self, $extra),
            'setattribute' => VmDomJitDispatch::setAttribute($ctx, $self, $extra),
            'add' => VmDomJitDispatch::tokenListAdd($ctx, $self, $extra),
            'remove' => VmDomJitDispatch::tokenListRemove($ctx, $self, $extra),
            'contains' => VmDomJitDispatch::tokenListContains($self, $extra),
            'item' => VmDomJitDispatch::tokenListItem($self, $extra),
            'toggle' => VmDomJitDispatch::tokenListToggle($ctx, $self, $extra),
            default => throw new \Error('Call to undefined method '.$self->class->name.'::'.$methodLc.'()'),
        };
    }
}
