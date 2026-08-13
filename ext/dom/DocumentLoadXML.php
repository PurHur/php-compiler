<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/** DOMDocument::loadXML() — VM (#11895, #19796, php-src ext/dom/document.c). */
final class DocumentLoadXML extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('loadXML');
    }

    public function execute(Frame $frame): void
    {
        $this->requireUserArgCountRange($frame, 'DOMDocument::loadXML', 1, 2);
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::loadXML()');
        // Z_PARAM_STR: strict null → TypeError; weak null → E_DEPRECATED then '' → ValueError (#30041, #22680).
        $xml = VmString::internalMethodStringArgForFrame(
            $frame,
            1,
            'DOMDocument::loadXML',
            0,
            'source'
        );
        $options = 0;
        if (isset($frame->calledArgs[2])) {
            // Z_PARAM_LONG $options (#25768).
            $options = $this->zParamLongArg($frame, 2, 'DOMDocument::loadXML', 2, 'options');
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
