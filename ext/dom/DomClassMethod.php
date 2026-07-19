<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\EnumCaseSupport;
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

    /**
     * insertAdjacent* $where — string on DOMElement; Dom\AdjacentPosition on living Dom\Element (#20782).
     *
     * php-src: ext/dom/php_dom.stub.php — DOMElement string vs Dom\Element AdjacentPosition
     */
    protected function adjacentWhereArg(ObjectEntry $receiver, Variable $var, string $method): string
    {
        $var = $var->resolveIndirect();
        $living = VmDomLiving::isLivingElement($receiver);
        $label = $living
            ? 'Dom\\Element::'.$method
            : 'DOMElement::'.$method;

        if ($living) {
            if (!EnumCaseSupport::isEnumCaseVariable($var)) {
                $given = Variable::TYPE_OBJECT === $var->type
                    ? $var->toObject()->class->name
                    : VmDom::typeLabel($var);
                throw new \TypeError(sprintf(
                    '%s(): Argument #1 ($where) must be of type Dom\\AdjacentPosition, %s given',
                    $label,
                    $given
                ));
            }
            $entry = EnumCaseSupport::enumCaseEntryForVariable($var);
            if (null === $entry || VmDomLiving::CLASS_ADJACENT_POSITION !== strtolower($entry->enumClass->name)) {
                throw new \TypeError(sprintf(
                    '%s(): Argument #1 ($where) must be of type Dom\\AdjacentPosition, %s given',
                    $label,
                    EnumCaseSupport::typeNameForVariable($var)
                ));
            }
            $backing = $entry->backingValue->resolveIndirect();
            if (Variable::TYPE_STRING !== $backing->type) {
                throw new \TypeError(sprintf(
                    '%s(): Argument #1 ($where) must be of type Dom\\AdjacentPosition, %s given',
                    $label,
                    EnumCaseSupport::typeNameForVariable($var)
                ));
            }

            return $backing->toString();
        }

        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($where) must be of type string, %s given',
                $label,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING !== $var->type && Variable::TYPE_NULL !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($where) must be of type string, %s given',
                $label,
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

    /**
     * DOMDocument::saveXML/saveHTML — ?DOMNode $node, int $options (php-src ext/dom/document.c).
     *
     * @return array{0: ?ObjectEntry, 1: int}
     */
    protected function parseSaveNodeAndOptionsArgs(Frame $frame, string $label): array
    {
        $node = null;
        $options = 0;
        $argc = \count($frame->calledArgs) - 1;
        if ($argc >= 1) {
            $first = $frame->calledArgs[1]->resolveIndirect();
            if (1 === $argc && \in_array($first->type, [Variable::TYPE_INTEGER, Variable::TYPE_FLOAT], true)) {
                $options = $first->toInt();
            } else {
                $node = $this->saveSerializationOptionalDomNodeArg($frame->calledArgs[1], $label, 0);
                if ($argc >= 2) {
                    $options = $this->optionsIntArg($frame->calledArgs[2], $label, 1);
                }
            }
        }

        return [$node, $options];
    }

    protected function saveSerializationOptionalDomNodeArg(Variable $var, string $label, int $index): ?ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s expects argument #%d to be of type ?DOMNode, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        if (!VmDom::isDomNode($object)) {
            throw new \TypeError(\sprintf(
                '%s expects argument #%d to be of type ?DOMNode, %s given',
                $label,
                $index + 1,
                $object->class->name
            ));
        }

        return $object;
    }

    protected function optionsIntArg(Variable $var, string $label, int $index): int
    {
        $var = $var->resolveIndirect();
        if (!\in_array($var->type, [Variable::TYPE_INTEGER, Variable::TYPE_FLOAT], true)) {
            throw new \TypeError(\sprintf(
                '%s expects argument #%d to be of type int, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }

        return $var->toInt();
    }
}
