<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmExecutingFrame;

/**
 * get_object_vars() / get_mangled_object_vars() for compiled JIT/AOT modules (#16629, php-in-PHP).
 *
 * SSOT: {@see VmReflection::getObjectVars()}, {@see VmReflection::getMangledObjectVars()}
 * php-src: ext/standard/var.c — PHP_FUNCTION(get_object_vars)
 *
 * Frame lookup goes through {@see VmExecutingFrame} so NestedJIT does not resolve
 * `$vm->currentExecutingFrame()` against this helper class (#22547).
 *
 * Return type is NestedJIT `__value__*` ABI for {@see Variable} — ClassReturnCheck must
 * not treat it as a normal object return (#26797 / peer HashTable #21888).
 */
final class GetObjectVarsJitHelper
{
    public static function objectVarsArgv(Variable $object, int $mangled): Variable
    {
        $frame = VmExecutingFrame::requireFromActiveContext();

        return 0 !== $mangled
            ? VmReflection::getMangledObjectVars($object, $frame)
            : VmReflection::getObjectVars($object, $frame);
    }
}
