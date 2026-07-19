<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Dom\Element::rename() — VM (php-src ext/dom/element.c; #20924). */
final class ElementRename extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('rename');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->livingElementReceiver($frame, 'Dom\\Element::rename()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 2) {
            throw new \ArgumentCountError(sprintf(
                'Dom\\Element::rename() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'Dom\\Element::rename() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $namespaceUri = $this->nullableStringArg($frame->calledArgs[1], 'Dom\\Element::rename()', 0);
        $qualifiedName = $this->stringArg($frame->calledArgs[2], 'Dom\\Element::rename()', 1);
        VmDomLiving::renameElement($element, $namespaceUri, $qualifiedName);
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
