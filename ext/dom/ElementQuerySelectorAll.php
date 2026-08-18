<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Dom\Element::querySelectorAll() — VM (php-src ext/dom/parentnode.c; #20418). */
final class ElementQuerySelectorAll extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('querySelectorAll');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Dom\\Element::querySelectorAll() requires VM context');
        $element = $this->livingElementReceiver($frame, 'Dom\\Element::querySelectorAll()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Dom\\Element::querySelectorAll() expects exactly 1 argument, 0 given');
        }
        $selectors = $this->stringArg($frame->calledArgs[1], 'Dom\\Element::querySelectorAll()', 0);
        $list = VmDomLiving::querySelectorAll($ctx, $element, $selectors);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($list): void {
            $ret->copyFrom($list);
        });
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
        // ParentNode: Element + DocumentFragment (php-src php_dom.stub.php; #32132 :root).
        if (VmDom::isDocumentFragment($object)) {
            return $object;
        }
        if (!VmDomLiving::isLivingElement($object) || !VmDom::isElement($object)) {
            throw new \TypeError($label.' must be called on a Dom\\Element instance');
        }

        return $object;
    }
}
