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
    /** Zend zend_generators.c — Generator::rewind() on started generator (#5195). */
    public const REWIND_ALREADY_RUN_ERROR = 'Cannot rewind a generator that was already run';

    /** Zend zend_generators.c — foreach / iterator_count on closed generator (#5132, #17368). */
    public const CLOSED_TRAVERSE_ERROR = 'Cannot traverse an already closed generator';

    /** Zend zend_generators.c — yield inside finally during forced close (#19905). */
    public const FORCED_CLOSE_YIELD_ERROR = 'Cannot yield from finally in a force-closed generator';

    public bool $done = false;

    /** True after the generator body has been entered (Zend rewind guard, #5195). */
    public bool $started = false;

    /**
     * Zend ZEND_GENERATOR_AT_FIRST_YIELD — rewind allowed while still on the opening yield (#23713).
     *
     * Set after the initial open (current/key/valid/rewind); cleared by every subsequent resume
     * (next/send/throw). Rewind throws only when this flag is false.
     */
    public bool $atFirstYield = false;

    public bool $hasCurrent = false;

    /**
     * Next auto-increment yield key (Zend largest_used_integer_key + 1).
     *
     * Starts at 0. Explicit non-negative integer keys bump this to max(autoKey, key+1)
     * with zend_long wrap after PHP_INT_MAX (#22343 / zend_generators.c).
     */
    public int $autoKey = 0;

    public Variable $currentKey;

    public Variable $currentValue;

    /** Snapshot for current() — return slots may alias {@see $currentValue} (#18183). */
    public Variable $currentSnapshot;

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

    /**
     * Foreach ITER_VALID advance-on-valid protocol (#23713).
     *
     * False after RESET while already positioned on a yield (rewind/current); first VALID
     * reports true without advancing. Subsequent VALIDs resume like the legacy open-on-valid path.
     */
    public bool $foreachNeedsAdvance = false;

    /**
     * True while running finally during early close (Zend ZEND_GENERATOR_FORCED_CLOSE, #19905).
     *
     * Yield inside finally while this flag is set must Error like php-src.
     */
    public bool $forcedClose = false;

    /** Closure binding when this generator was created from a closure (#6567). */
    public ?ClosureState $closureCall = null;

    /**
     * Try handlers active when this generator last suspended (yield / catch-yield).
     *
     * Isolated from the caller's {@see Context::$activeTryHandlerFrames} so a suspended
     * generator try/catch cannot absorb uncaught exceptions in the caller (#22869).
     *
     * @var list<Frame>
     */
    public array $suspendedTryHandlerFrames = [];

    /**
     * Merge-block ids paired with {@see $suspendedTryHandlerFrames} (#22869).
     *
     * @var array<int, true>
     */
    public array $suspendedTryMergeBlockIds = [];

    /** @var list<Variable> */
    public readonly array $calledArgs;

    /**
     * @param list<Variable> $calledArgs
     */
    public function __construct(
        public readonly \PHPCompiler\VM $vm,
        public readonly Func\PHP $func,
        array $calledArgs,
    ) {
        // Snapshot call args so generator lifetime is not tied to caller scope slots
        // (temps like `(new G())->gen()` otherwise lose $this — #22067).
        $snap = [];
        foreach ($calledArgs as $i => $arg) {
            $copy = new Variable();
            $copy->duplicateFrom($arg->resolveIndirect());
            $snap[$i] = $copy;
        }
        $this->calledArgs = $snap;
        $this->currentKey = new Variable();
        $this->currentKey->generatorYieldStorage = true;
        $this->currentValue = new Variable();
        $this->currentValue->generatorYieldStorage = true;
        $this->currentSnapshot = new Variable();
        $this->yieldFromContainer = new Variable();
        $this->returnValue = new Variable();
        $this->pendingSend = new Variable();
        $this->pendingSend->null();
        $this->pendingThrow = new Variable();
        $this->pendingThrow->null();
    }

    /** Publish yielded value; keep snapshot for idempotent current() (#18183). */
    public function publishCurrentValue(Variable $value): void
    {
        $this->currentValue->duplicateFrom($value);
        $this->currentSnapshot->duplicateFrom($this->currentValue);
        $this->hasCurrent = true;
    }

    /**
     * Assign the next auto-increment key and advance (Zend ++largest_used_integer_key).
     */
    public function takeNextAutoKey(): int
    {
        $key = $this->autoKey;
        if (PHP_INT_MAX === $this->autoKey) {
            $this->autoKey = PHP_INT_MIN;
        } else {
            ++$this->autoKey;
        }

        return $key;
    }

    /**
     * After publishing an explicit yield key, update auto-increment like Zend (#22343).
     *
     * Only IS_LONG keys participate; floats/strings/negatives that are not greater
     * than the current largest leave autoKey unchanged. Yield-from delegated keys
     * do not call this (php-src leaves largest_used_integer_key alone).
     */
    public function noteExplicitYieldKey(Variable $key): void
    {
        $key = $key->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $key->type) {
            return;
        }
        $k = $key->toInt();
        // autoKey is next key (= largest + 1). Update when k > largest, i.e. k >= autoKey
        // for the common non-wrapped range. After publishing PHP_INT_MAX, autoKey wraps to
        // PHP_INT_MIN; further explicit keys must not change it (0 > PHP_INT_MAX is false).
        if (PHP_INT_MIN === $this->autoKey) {
            return;
        }
        if ($k >= $this->autoKey) {
            if (PHP_INT_MAX === $k) {
                $this->autoKey = PHP_INT_MIN;
            } else {
                $this->autoKey = $k + 1;
            }
        }
    }

    public function clearCurrentValue(): void
    {
        $this->hasCurrent = false;
        $this->currentValue->null();
        $this->currentSnapshot->null();
    }

    public static function register(Context $ctx): void
    {
        $entry = new ClassEntry('Generator');
        $entry->isFinal = true;
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
        $this->clearSuspendedTryState();
        $this->clearCurrentValue();
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
        $this->clearSuspendedTryState();
        $this->clearCurrentValue();
    }

    /** Drop try/catch isolation state when the generator is no longer suspended (#22869). */
    public function clearSuspendedTryState(): void
    {
        $this->suspendedTryHandlerFrames = [];
        $this->suspendedTryMergeBlockIds = [];
    }

    public function wrapObject(): ObjectEntry
    {
        $entry = new ObjectEntry($this->vm->context->classes['generator']);
        $entry->generatorState = $this;
        $entry->constructed = true;

        return $entry;
    }

    /** Foreach ITER_RESET — closed/advanced gens error; leave unstarted for open-on-valid (#17368, #23713). */
    public function rewindForForeach(): void
    {
        if ($this->done) {
            throw new \Exception(self::CLOSED_TRAVERSE_ERROR);
        }
        if ($this->started && !$this->atFirstYield) {
            throw new \Exception(self::REWIND_ALREADY_RUN_ERROR);
        }
        // Already on opening yield (current/key/valid/rewind): keep position.
        // Unstarted: do not open here — ITER_VALID advances to the first yield.
        $this->foreachNeedsAdvance = false;
    }

    /**
     * Zend zend_generator_rewind — ensure opened at first yield; no-op while AT_FIRST_YIELD (#23713).
     *
     * Does not re-execute the generator body when already suspended on the opening yield.
     */
    public function rewind(): void
    {
        if (!$this->done && !$this->hasCurrent && !$this->started && !$this->hasReturned) {
            $this->vm->resumeGenerator($this);
            $this->atFirstYield = true;
        }
        if (!$this->atFirstYield) {
            throw new \Exception(self::REWIND_ALREADY_RUN_ERROR);
        }
        $this->foreachNeedsAdvance = false;
    }
}
