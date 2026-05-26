<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\Func;

/**
 * VM state for a user generator (`function g() { yield $v; }`, issue #167).
 */
final class GeneratorState
{
    public bool $done = false;

    public bool $hasCurrent = false;

    public int $autoKey = 0;

    public Variable $currentKey;

    public Variable $currentValue;

    public ?Frame $frame = null;

    public function __construct(
        public readonly \PHPCompiler\VM $vm,
        public readonly Func\PHP $func,
        /** @var list<Variable> */
        public readonly array $calledArgs,
    ) {
        $this->currentKey = new Variable();
        $this->currentValue = new Variable();
    }

    public static function register(Context $ctx): void
    {
        $ctx->classes['generator'] = new ClassEntry('Generator');
    }

    public function wrapObject(): ObjectEntry
    {
        $entry = new ObjectEntry($this->vm->context->classes['generator']);
        $entry->generatorState = $this;
        $entry->constructed = true;

        return $entry;
    }

    public function rewind(): void
    {
        $this->done = false;
        $this->hasCurrent = false;
        $this->frame = null;
        $this->autoKey = 0;
    }
}
