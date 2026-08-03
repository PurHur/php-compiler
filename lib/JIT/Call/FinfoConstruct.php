<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * finfo::__construct() — mark constructed for thin AOT (#27196, re-#3366).
 *
 * Flags live in the VM side table ({@see \PHPCompiler\ext\fileinfo\VmFinfo}); file()
 * AOT currently sniffs MIME via path (Done-when FILEINFO_MIME_TYPE). Property-backed
 * flags for FILEINFO_NONE / set_flags can follow without changing this entry shape.
 *
 * php-src: ext/fileinfo/fileinfo.c — zim_finfo___construct
 */
final class FinfoConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \ArgumentCountError(
                'finfo::__construct() expects at most 2 arguments, 0 given'
            );
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $context->type->object->lookup('finfo');
        ReflectionSetup::markConstructed($context, $obj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
