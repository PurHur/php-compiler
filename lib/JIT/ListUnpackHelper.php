<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitArrayIsList;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;

/** Runtime guard for `list()` / `[]` destructuring on non-list arrays (#4298). */
final class ListUnpackHelper
{
    public const TYPE_ERROR_MESSAGE = 'Cannot unpack array with string keys';

    public static function emitCheck(Context $context, Variable $array): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $isList = JitArrayIsList::invoke($context, $array);
        $failBb = BasicBlockHelper::append($context, 'list_unpack_fail');
        $okBb = BasicBlockHelper::append($context, 'list_unpack_ok');
        $context->builder->branchIf($isList, $okBb, $failBb);
        $context->builder->positionAtEnd($failBb);
        TypeErrorRaise::emitRaise($context, self::TYPE_ERROR_MESSAGE);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->unreachable();
        $context->builder->positionAtEnd($okBb);
        $context->builder->positionAtEnd($okBb);
    }
}
