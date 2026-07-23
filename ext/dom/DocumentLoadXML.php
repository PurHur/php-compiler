<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMDocument::loadXML() — VM (#11895, #19796, php-src ext/dom/document.c). */
final class DocumentLoadXML extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('loadXML');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::loadXML()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocument::loadXML() expects at least 1 argument');
        }
        // Z_PARAM_STR: null → E_DEPRECATED then '' → ValueError empty (#22680).
        $xml = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'DOMDocument::loadXML', 0, 'source');
        $options = 0;
        if (isset($frame->calledArgs[2])) {
            $optionsVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $optionsVar->type) {
                throw new \TypeError('DOMDocument::loadXML(): Argument #2 ($options) must be of type int');
            }
            $options = $optionsVar->toInt();
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::loadXML() requires VM context in this compiler build');
        }
        $ok = VmDom::loadXML($frame->vmContext, $receiver, $xml, $frame, $options);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
