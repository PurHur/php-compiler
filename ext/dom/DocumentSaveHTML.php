<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMDocument::saveHTML() — VM (#14356, php-src ext/dom/php_dom.c). */
final class DocumentSaveHTML extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('saveHTML');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::saveHTML()');
        $node = null;
        if (isset($frame->calledArgs[1])) {
            $node = $this->optionalDomNodeArg($frame->calledArgs[1], 'DOMDocument::saveHTML()', 0);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmDom::saveHTML($receiver, $node));
        }
    }

    private function optionalDomNodeArg(Variable $var, string $label, int $index): ?\PHPCompiler\VM\ObjectEntry
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
}
