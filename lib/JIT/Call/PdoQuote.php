<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * PDO::quote() — thin AOT fail-closed (#27619).
 *
 * php-src: ext/pdo/pdo_dbh.c — zim_PDO_quote
 *
 * Thin user-script AOT has no native sqlite quote helper yet. Prefer a catchable
 * {@see \PDOException} over ExternalMethod silent NULL (#579 / artifact-honesty).
 * Full quote when a driver handle exists remains a follow-on.
 */
final class PdoQuote implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('PDO::quote() called without $this');
        }
        if (\count($args) < 2) {
            throw new \ArgumentCountError(
                'PDO::quote() expects at least 1 argument, 0 given'
            );
        }

        TryCatchHelper::emitCatchableClassError(
            $context,
            'PDOException',
            'PDO object has not been correctly initialized by its constructor'
        );
        $unreachable = BasicBlockHelper::append($context, 'pdo_quote_uninit_unreach');
        $context->builder->positionAtEnd($unreachable);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
