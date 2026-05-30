<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Func;

/**
 * VM state for anonymous functions / closures (issue #72, #3266).
 *
 * Closures are represented as objects with an attached {@see ClosureState} rather than
 * registering each instance as a named user function.
 */
final class ClosureState
{
    /**
     * Bound `use ($var)` values captured when the closure object was created.
     *
     * @var list<array{slot: int, var: Variable, byRef: bool}>
     */
    public array $captures;

    /** When set, invoke this target instead of {@see $func} (fromCallable string/static). */
    public ?Func $wrappedFunc = null;

    /** When set, invoke as instance method on {@see $methodReceiver}. */
    public ?Variable $methodReceiver = null;

    public ?string $methodName = null;

    /** Rebound $this from bindTo / fromCallable instance method wrapper. */
    public ?Variable $boundThis = null;

    /** Class scope name for private/protected access (bindTo / fromCallable). */
    public ?string $boundScopeClass = null;

    /**
     * @param list<array{slot: int, var: Variable, byRef: bool}> $captures
     */
    public function __construct(
        public readonly Func\PHP $func,
        array $captures = [],
    ) {
        $this->captures = $captures;
    }

    public static function fromWrappedFunc(Func $func): self
    {
        $stub = new Func\PHP('{closure}', new \PHPCompiler\Block(null));
        $state = new self($stub);
        $state->wrappedFunc = $func;

        return $state;
    }

    public static function fromMethodCallable(Func $func, Variable $receiver, string $methodName): self
    {
        $stub = new Func\PHP('{closure}', new \PHPCompiler\Block(null));
        $state = new self($stub);
        $state->wrappedFunc = $func;
        $state->methodReceiver = $receiver;
        $state->methodName = $methodName;

        return $state;
    }

    public function cloneForBind(): self
    {
        $captures = [];
        foreach ($this->captures as $capture) {
            $stored = new Variable();
            if ($capture['byRef']) {
                $stored->indirect($capture['var']->resolveIndirect());
            } else {
                $stored->copyFrom($capture['var']);
            }
            $captures[] = [
                'slot' => $capture['slot'],
                'var' => $stored,
                'byRef' => $capture['byRef'],
            ];
        }
        $clone = new self($this->func, $captures);
        $clone->boundThis = null !== $this->boundThis ? $this->copyVar($this->boundThis) : null;
        $clone->boundScopeClass = $this->boundScopeClass;

        return $clone;
    }

    public function isUserClosure(): bool
    {
        return null === $this->wrappedFunc && null === $this->methodName;
    }

    public static function register(Context $ctx): void
    {
        $entry = new ClassEntry('Closure');
        $pub = \PHPCfg\Func::FLAG_PUBLIC;
        $entry->methods['fromcallable'] = new Builtin\ClosureFromCallable();
        $entry->methodVisibility['fromcallable'] = $pub;
        $entry->methods['bind'] = new Builtin\ClosureBind();
        $entry->methodVisibility['bind'] = $pub;
        $entry->methods['bindto'] = new Builtin\ClosureBindTo();
        $entry->methodVisibility['bindto'] = $pub;
        $ctx->classes['closure'] = $entry;
    }

    public function wrapObject(Context $ctx): ObjectEntry
    {
        $entry = new ObjectEntry($ctx->classes['closure']);
        $entry->closureState = $this;
        $entry->constructed = true;

        return $entry;
    }

    private function copyVar(Variable $src): Variable
    {
        $copy = new Variable();
        $copy->copyFrom($src->resolveIndirect());

        return $copy;
    }
}
