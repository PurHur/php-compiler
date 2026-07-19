<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Dom\Attr::rename() — VM (php-src ext/dom/element.c PHP_METHOD(Dom_Element, rename)
 * via @implementation-alias; #21083).
 */
final class AttrRename extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('rename');
    }

    public function execute(Frame $frame): void
    {
        $attr = $this->livingAttrReceiver($frame, 'Dom\\Attr::rename()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc !== 2) {
            throw new \ArgumentCountError(sprintf(
                'Dom\\Attr::rename() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $namespaceUri = $this->nullableStringArg($frame->calledArgs[1], 'Dom\\Attr::rename()', 0);
        $qualifiedName = $this->stringArg($frame->calledArgs[2], 'Dom\\Attr::rename()', 1);
        VmDomLiving::renameAttr($frame->vmContext, $attr, $namespaceUri, $qualifiedName);
    }

    private function livingAttrReceiver(Frame $frame, string $label): ObjectEntry
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
        if (!VmDomLiving::isLivingAttr($object) || !VmDom::isAttr($object)) {
            throw new \TypeError($label.' must be called on a Dom\\Attr instance');
        }

        return $object;
    }
}
