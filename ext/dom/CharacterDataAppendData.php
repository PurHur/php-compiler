<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMCharacterData::appendData() — VM (#17514, php-src ext/dom/characterdata.c).
 *
 * Exact user arity 1 — Zend ArgumentCountError (#31091; re-#31011 / #30616).
 */
final class CharacterDataAppendData extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('appendData');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMCharacterData::appendData', 1);
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMCharacterData::appendData()');
        if (!VmDom::isCharacterData($receiver)) {
            throw new \TypeError('DOMCharacterData::appendData() must be called on a character data node');
        }
        $arg = $this->stringArg($frame->calledArgs[1], 'DOMCharacterData::appendData()', 0, $frame, 'data');
        VmDom::characterDataAppendData($receiver, $arg);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
