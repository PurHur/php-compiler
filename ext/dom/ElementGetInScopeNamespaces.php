<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Dom\Element::getInScopeNamespaces() — VM (php-src ext/dom/element.c; #20924). */
final class ElementGetInScopeNamespaces extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getInScopeNamespaces');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->livingElementReceiver($frame, 'Dom\\Element::getInScopeNamespaces()');
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }
        $infos = VmDomLiving::getInScopeNamespaces($frame->vmContext, $element);
        $frame->returnVar->array(VmDomLiving::namespaceInfoListToArray($infos));
    }

    private function livingElementReceiver(Frame $frame, string $label): ObjectEntry
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
        if (!VmDomLiving::isLivingElement($object) || !VmDom::isElement($object)) {
            throw new \TypeError($label.' must be called on a Dom\\Element instance');
        }

        return $object;
    }
}
