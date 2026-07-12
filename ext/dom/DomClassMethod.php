<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Shared VM wiring for ext/dom class methods (issue #6140). */
abstract class DomClassMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $classLc, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return VmDom::requireReceiver($frame->calledArgs[0], $classLc, $label, $frame->vmContext);
    }

    protected function stringArg(
        Variable $var,
        string $label,
        int $index,
        ?Frame $frame = null,
        string $paramName = 'value'
    ): string {
        if (null !== $frame && InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::rejectNullString($var, $label, $paramName, $index, $frame);
        }
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type && Variable::TYPE_NULL !== $var->type) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type string, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }
        if (Variable::TYPE_NULL === $var->type) {
            return '';
        }

        return $var->toString();
    }

    protected function nullableStringArg(Variable $var, string $label, int $index): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type ?string, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }

        return $var->toString();
    }

    protected function domRegistryNodeReceiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s must be called on an object, %s given',
                $label,
                VmDom::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        $classLc = strtolower($object->class->name);
        if (VmDom::CLASS_DOCUMENT === $classLc) {
            VmDom::ensureDocument($object);
        } elseif (VmDom::CLASS_DOCUMENT_FRAGMENT === $classLc) {
            VmDom::ensureDocumentFragment($object);
        }
        if (!VmDom::isDomNode($object)) {
            throw new \TypeError(sprintf(
                '%s must be called on a DOMNode instance',
                $label
            ));
        }

        return $object;
    }
}
