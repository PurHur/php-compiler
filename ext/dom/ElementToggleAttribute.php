<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMElement::toggleAttribute() — VM (#16824, php-src ext/dom/element.c). */
final class ElementToggleAttribute extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('toggleAttribute');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::toggleAttribute()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMElement::toggleAttribute() expects at least 1 argument');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'DOMElement::toggleAttribute()', 0);
        $force = null;
        if (isset($frame->calledArgs[2])) {
            $force = self::nullableBoolArg($frame->calledArgs[2], 'DOMElement::toggleAttribute()', 1);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::toggleAttribute($frame->vmContext, $element, $name, $force));
    }

    private static function nullableBoolArg(Variable $var, string $label, int $index): ?bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type ?bool, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }

        return $var->toBool();
    }
}
