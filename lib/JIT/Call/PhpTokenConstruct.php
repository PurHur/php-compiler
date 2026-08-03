<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\tokenizer\VmPhpToken;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * PhpToken::__construct(int $id, string $text, int $line = -1, int $pos = -1) — JIT/AOT (#27263).
 *
 * php-src: ext/tokenizer/tokenizer.c — PhpToken::__construct
 * VM SSOT: {@see \PHPCompiler\ext\tokenizer\PhpTokenConstruct}
 */
final class PhpTokenConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \ArgumentCountError(
                'PhpToken::__construct() expects at least 2 arguments, '.\max(0, \count($args) - 1).' given'
            );
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $context->type->object->lookup('PhpToken');

        $id = JitLongArg::lower($context, $args[1], 'PhpToken::__construct id');
        $idVar = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $id
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, 'PhpToken', VmPhpToken::PROP_ID),
            $idVar,
            Variable::TYPE_NATIVE_LONG
        );

        ReflectionSetup::emitSetStringPropertyFromVar(
            $context,
            $obj,
            'PhpToken',
            VmPhpToken::PROP_TEXT,
            $args[2]
        );

        $line = \count($args) >= 4
            ? JitLongArg::lower($context, $args[3], 'PhpToken::__construct line')
            : $context->constantFromInteger(-1);
        $lineVar = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $line
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, 'PhpToken', VmPhpToken::PROP_LINE),
            $lineVar,
            Variable::TYPE_NATIVE_LONG
        );

        $pos = \count($args) >= 5
            ? JitLongArg::lower($context, $args[4], 'PhpToken::__construct pos')
            : $context->constantFromInteger(-1);
        $posVar = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $pos
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, 'PhpToken', VmPhpToken::PROP_POS),
            $posVar,
            Variable::TYPE_NATIVE_LONG
        );

        ReflectionSetup::markConstructed($context, $obj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
