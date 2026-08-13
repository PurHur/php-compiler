<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * Exact user-arity gate for synthetic static enum methods (cases/from/tryFrom) (#30864).
 *
 * Native LLVM bodies ignore excess SEND ops; wrap them so JIT/AOT match Zend ACE text.
 * php-src: Zend/zend_enum.c — zend_enum_cases_func / zend_enum_from_func / zend_enum_try_from_func
 */
final class EnumSyntheticStatic implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name;

    /** @var list<string> */
    public array $paramNames;

    /** Static — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function __construct(
        private Native $inner,
        string $function,
        private int $exactUserArgCount,
    ) {
        $this->name = $function;
        $this->paramNames = 1 === $exactUserArgCount ? ['value'] : [];
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $given = \count($args);
        if ($given !== $this->exactUserArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage($this->name, $this->exactUserArgCount, $given)
            );
            $unreachable = BasicBlockHelper::append($context, 'enum_synthetic_argc_unreach');
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        return $this->inner->call($context, ...$args);
    }
}
