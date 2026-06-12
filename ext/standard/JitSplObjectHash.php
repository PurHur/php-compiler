<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;

/** LLVM lowering for spl_object_hash() — format handle like php_spl_object_hash() (#3172). */
final class JitSplObjectHash
{
    public static function invoke(Context $context, JITVariable $arg): \PHPLLVM\Value
    {
        $id = JitGetObjectId::invoke($context, $arg, 'spl_object_hash');
        $fmt = $context->builder->load($context->constantStringFromString('%016llx0000000000000000'));
        $idVar = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $id);

        return JitSprintf::formatWithFmt($context, $fmt, $idVar);
    }
}
