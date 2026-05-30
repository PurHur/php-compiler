<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCompiler\VM\Builtin\GeneratorGetReturn;

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

    public bool $yieldFromActive = false;

    public Variable $yieldFromContainer;

    /** True after the generator body has returned (void or value). */
    public bool $hasReturned = false;

    public Variable $returnValue;

    public function __construct(
        public readonly \PHPCompiler\VM $vm,
        public readonly Func\PHP $func,
        /** @var list<Variable> */
        public readonly array $calledArgs,
    ) {
        $this->currentKey = new Variable();
        $this->currentValue = new Variable();
        $this->yieldFromContainer = new Variable();
        $this->returnValue = new Variable();
    }

    public static function register(Context $ctx): void
    {
        $entry = new ClassEntry('Generator');
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->methods['getreturn'] = new GeneratorGetReturn();
        $entry->methodVisibility['getreturn'] = $pub;
        $ctx->classes['generator'] = $entry;
    }

    public function markReturned(?Variable $value = null): void
    {
        $this->done = true;
        $this->frame = null;
        $this->hasCurrent = false;
        $this->hasReturned = true;
        if (null !== $value) {
            $this->returnValue->copyFrom($value);
        } else {
            $this->returnValue->null();
        }
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
        $this->yieldFromActive = false;
        $this->hasReturned = false;
        $this->returnValue->null();
    }
}
