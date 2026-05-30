<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;

use PHPLLVM\Value;

/**
 * JIT closure invoke proxy: appends bound use() snapshots after call args (issue #72).
 */
final class ClosureWithCaptures implements Call
{
    /**
     * @param list<Variable> $captures Snapshots in {@see ClosureHelper::orderedCaptureSlots()} order.
     */
    public function __construct(
        private readonly Native $inner,
        private readonly array $captures,
    ) {
    }

    public function innerNative(): Native
    {
        return $this->inner;
    }

    /** @return list<Variable> */
    public function captureVariables(): array
    {
        return $this->captures;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return $this->inner->call($context, ...$args, ...$this->captures);
    }
}
