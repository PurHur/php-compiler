<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::saveXML() — VM (#6140). */
final class DocumentSaveXML extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('saveXML');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::saveXML()');
        $node = null;
        if (isset($frame->calledArgs[1])) {
            $node = $this->optionalDomNodeArg($frame->calledArgs[1], 'DOMDocument::saveXML()', 0);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmDom::saveXML($receiver, $node));
        }
    }

    private function optionalDomNodeArg(\PHPCompiler\VM\Variable $var, string $label, int $index): ?\PHPCompiler\VM\ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (\PHPCompiler\VM\Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type ?DOMNode, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        if (!VmDom::isDomNode($object)) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type ?DOMNode, %s given',
                $label,
                $index + 1,
                $object->class->name
            ));
        }

        return $object;
    }
}
