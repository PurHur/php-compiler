<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCompiler\VM\Builtin\GeneratorCurrent;
use PHPCompiler\VM\Builtin\GeneratorGetReturn;
use PHPCompiler\VM\Builtin\GeneratorKey;
use PHPCompiler\VM\Builtin\GeneratorNext;
use PHPCompiler\VM\Builtin\GeneratorRewind;
use PHPCompiler\VM\Builtin\GeneratorSend;
use PHPCompiler\VM\Builtin\GeneratorThrow;
use PHPCompiler\VM\Builtin\GeneratorValid;

/**
 * VM state for a user generator (`function g() { yield $v; }`, issue #167).
 */
final class GeneratorState
{
    public bool $done = false;

    /** True after the generator body has been entered (Zend rewind guard, #5195). */
    public bool $started = false;

    public bool $hasCurrent = false;

    public int $autoKey = 0;

    public Variable $currentKey;

    public Variable $currentValue;

    public ?Frame $frame = null;

    public bool $yieldFromActive = false;

    /** Iterator protocol advance pending (Zend foreach/yield-from parity, #4338). */
    public bool $yieldFromIteratorAdvance = false;

    public Variable $yieldFromContainer;

    /** True after the generator body has returned (void or value). */
    public bool $hasReturned = false;

    public Variable $returnValue;

    /** Scope slot receiving Generator::send() value for `yield` expression result (#167). */
    public ?int $yieldResultSlot = null;

    public bool $hasPendingSend = false;

    public Variable $pendingSend;

    public bool $hasPendingThrow = false;

    public Variable $pendingThrow;

    /** Foreach iteration must observe yielded values before a trailing throw (#13366). */
    public bool $foreachAdvance = false;

    /** Closure binding when this generator was created from a closure (#6567). */
    public ?ClosureState $closureCall = null;

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
        $this->pendingSend = new Variable();
        $this->pendingSend->null();
        $this->pendingThrow = new Variable();
        $this->pendingThrow->null();
    }

    public static function register(Context $ctx): void
    {
        $entry = new ClassEntry('Generator');
        $entry->interfaces = ['iterator'];
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->methods['getreturn'] = new GeneratorGetReturn();
        $entry->methodVisibility['getreturn'] = $pub;
        $entry->methods['send'] = new GeneratorSend();
        $entry->methodVisibility['send'] = $pub;
        $entry->methods['throw'] = new GeneratorThrow();
        $entry->methodVisibility['throw'] = $pub;
        $entry->methods['rewind'] = new GeneratorRewind();
        $entry->methodVisibility['rewind'] = $pub;
        $entry->methods['current'] = new GeneratorCurrent();
        $entry->methodVisibility['current'] = $pub;
        $entry->methods['key'] = new GeneratorKey();
        $entry->methodVisibility['key'] = $pub;
        $entry->methods['valid'] = new GeneratorValid();
        $entry->methodVisibility['valid'] = $pub;
        $entry->methods['next'] = new GeneratorNext();
        $entry->methodVisibility['next'] = $pub;
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

    /** Close after uncaught throw — done but not returned (Zend getReturn guard, #13027). */
    public function markClosedWithoutReturn(): void
    {
        $this->done = true;
        $this->frame = null;
        $this->hasCurrent = false;
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
        if ($this->started) {
            throw new \Exception('Cannot rewind a generator that was already run');
        }
        $this->done = false;
        $this->hasCurrent = false;
        $this->frame = null;
        $this->autoKey = 0;
        $this->yieldFromActive = false;
        $this->yieldFromIteratorAdvance = false;
        $this->hasReturned = false;
        $this->returnValue->null();
        $this->yieldResultSlot = null;
        $this->hasPendingSend = false;
        $this->pendingSend->null();
        $this->hasPendingThrow = false;
        $this->pendingThrow->null();
    }
}
