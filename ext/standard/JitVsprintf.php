<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin\StringFormat;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** vsprintf() JIT — hashtable values + __compiler_sprintf PHP tail (#15989). */
final class JitVsprintf
{
    public static function format(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(\sprintf(
                'vsprintf() expects exactly 2 arguments, %d given',
                \count($args)
            ));
        }
        JitVsprintfArrayArg::requireValues($context, $args[1], 'vsprintf');
        StringFormat::ensureLinked($context);
        $fmt = JitStringBuiltinArg::lowerRequiredString($context, $args[0], 'vsprintf', 0, 'format');
        $ht = ArrayBuiltinHelper::loadHashTable($context, $args[1]);
        $num = ArrayBuiltinHelper::getNumElements($context, $ht);
        $map = $context->structFieldMap['__hashtable__'];
        $valuesPtr = $context->builder->load($context->builder->structGep($ht, $map['values']));
        return $context->builder->call($context->lookupFunction('__compiler_sprintf'), $fmt, $num, $valuesPtr);
    }
}
