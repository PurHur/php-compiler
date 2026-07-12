<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMCharacterData::insertData() — VM (#17514, php-src ext/dom/characterdata.c). */
final class CharacterDataInsertData extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('insertData');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMCharacterData::insertData()');
        if (!VmDom::isCharacterData($receiver)) {
            throw new \TypeError('DOMCharacterData::insertData() must be called on a character data node');
        }
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMCharacterData::insertData() expects at least 2 arguments');
        }
        $offset = $this->intArg($frame->calledArgs[1], 'DOMCharacterData::insertData()', 0, 'offset');
        $arg = $this->stringArg($frame->calledArgs[2], 'DOMCharacterData::insertData()', 1, $frame, 'data');
        VmDom::characterDataInsertData($receiver, $offset, $arg);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    private function intArg(Variable $var, string $label, int $index, string $paramName): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type && Variable::TYPE_FLOAT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s: Argument #%d ($%s) must be of type int, %s given',
                $label,
                $index + 1,
                $paramName,
                VmDom::typeLabel($var)
            ));
        }

        return $var->toInt();
    }
}
