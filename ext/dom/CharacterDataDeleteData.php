<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMCharacterData::deleteData() — VM (#17514, php-src ext/dom/characterdata.c). */
final class CharacterDataDeleteData extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('deleteData');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMCharacterData::deleteData()');
        if (!VmDom::isCharacterData($receiver)) {
            throw new \TypeError('DOMCharacterData::deleteData() must be called on a character data node');
        }
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMCharacterData::deleteData() expects at least 2 arguments');
        }
        $offset = $this->intArg($frame->calledArgs[1], 'DOMCharacterData::deleteData()', 0, 'offset');
        $count = $this->intArg($frame->calledArgs[2], 'DOMCharacterData::deleteData()', 1, 'count');
        VmDom::characterDataDeleteData($receiver, $offset, $count);
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
