<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * DOMCharacterData::replaceData() — VM (#17514, php-src ext/dom/characterdata.c).
 *
 * Exact user arity 3 — Zend ArgumentCountError (#31091; re-#31011 / #30616).
 */
final class CharacterDataReplaceData extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('replaceData');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMCharacterData::replaceData', 3);
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMCharacterData::replaceData()');
        if (!VmDom::isCharacterData($receiver)) {
            throw new \TypeError('DOMCharacterData::replaceData() must be called on a character data node');
        }
        $offset = $this->intArg($frame->calledArgs[1], 'DOMCharacterData::replaceData()', 0, 'offset');
        $count = $this->intArg($frame->calledArgs[2], 'DOMCharacterData::replaceData()', 1, 'count');
        $arg = $this->stringArg($frame->calledArgs[3], 'DOMCharacterData::replaceData()', 2, $frame, 'data');
        VmDom::characterDataReplaceData($receiver, $offset, $count, $arg);
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
