<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\NaturalSortRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * natsort() — sort by value using natural order, preserve keys (subset of PHP; issue #2358).
 *
 * VM: homogeneous string or integer values; keys preserved on packed and assoc arrays (#9600).
 * JIT/AOT: packed list via rebuild preserve-keys; string-key via __hashtable__sortStringKeyValuesNatural.
 */
final class natsort_ extends Internal
{
    public function __construct()
    {
        parent::__construct('natsort');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('natsort() requires exactly one argument');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        $ht = VmArray::requireArray($frame->calledArgs[0], 'natsort');
        if ($ht->getNumElements() < 2) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        $array->array(VmArray::natsortCopy($ht));
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('natsort() requires exactly one argument');
        }
        JitArrayKey::requireArrayArg($context, $args[0], 'natsort');
        NaturalSortRuntime::natsortByValue($context, $args[0]);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }
}
