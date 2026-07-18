<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Dom\Element::closest() — VM (php-src ext/dom/php_dom.c; #20418). */
final class ElementClosest extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('closest');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->livingElementReceiver($frame, 'Dom\\Element::closest()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Dom\\Element::closest() expects exactly 1 argument, 0 given');
        }
        $selectors = $this->stringArg($frame->calledArgs[1], 'Dom\\Element::closest()', 0);
        $found = VmDomLiving::closest($element, $selectors);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($found): void {
            if (null === $found) {
                $ret->null();
            } else {
                $ret->object($found);
            }
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
        if (!VmDomLiving::isLivingElement($object) || !VmDom::isElement($object)) {
            throw new \TypeError($label.' must be called on a Dom\\Element instance');
        }

        return $object;
    }
}
