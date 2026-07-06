<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMCharacterData::substringData() — VM (#6250, php-src ext/dom/characterdata.c). */
final class CharacterDataSubstringData extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('substringData');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMCharacterData::substringData()');
        if (!VmDom::isCharacterData($receiver)) {
            throw new \TypeError('DOMCharacterData::substringData() must be called on a character data node');
        }
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMCharacterData::substringData() expects at least 2 arguments');
        }
        $offsetVar = $frame->calledArgs[1]->resolveIndirect();
        $countVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $offsetVar->type && Variable::TYPE_FLOAT !== $offsetVar->type) {
            throw new \TypeError(sprintf(
                'DOMCharacterData::substringData(): Argument #1 ($offset) must be of type int, %s given',
                VmDom::typeLabel($offsetVar)
            ));
        }
        if (Variable::TYPE_INTEGER !== $countVar->type && Variable::TYPE_FLOAT !== $countVar->type) {
            throw new \TypeError(sprintf(
                'DOMCharacterData::substringData(): Argument #2 ($count) must be of type int, %s given',
                VmDom::typeLabel($countVar)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmDom::characterDataSubstringData(
            $receiver,
            $offsetVar->toInt(),
            $countVar->toInt()
        ));
    }
}
