<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/**
 * DOMDocument::loadHTML() — VM (#14356, php-src ext/dom/php_dom.c).
 *
 * User arity 1–2 — Zend ArgumentCountError (#30835; missed by #30616).
 */
final class DocumentLoadHTML extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('loadHTML');
    }

    public function execute(Frame $frame): void
    {
        $this->requireUserArgCountRange($frame, 'DOMDocument::loadHTML', 1, 2);
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::loadHTML()');
        // Z_PARAM_STR: strict null → TypeError; weak null → E_DEPRECATED then '' → ValueError (#30041, #22680).
        $html = VmString::internalMethodStringArgForFrame(
            $frame,
            1,
            'DOMDocument::loadHTML',
            0,
            'source'
        );
        $options = 0;
        if (isset($frame->calledArgs[2])) {
            // Z_PARAM_LONG $options (#25768).
            $options = $this->zParamLongArg($frame, 2, 'DOMDocument::loadHTML', 2, 'options');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::loadHTML() requires VM context in this compiler build');
        }
        $ok = VmDom::loadHTML($frame->vmContext, $receiver, $html, $options, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
