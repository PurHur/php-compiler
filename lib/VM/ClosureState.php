<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\Compiler\SourceLocation;
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

    /** Definition site for var_dump / Closure::__debugInfo (issue #7069). */
    public string $definitionFile = '';

    public int $definitionLine = 0;

    /**
     * Per-closure static locals (Zend zend_closure static_variables; issue #4872).
     *
     * @var array<string, Variable>
     */
    private array $staticVars = [];

    /** @var array<string, true> */
    private array $staticInitialized = [];

    /**
     * @param list<array{slot: int, var: Variable, byRef: bool}> $captures
     */
    public function __construct(
        public readonly Func\PHP $func,
        array $captures = [],
    ) {
        $this->captures = $captures;
    }

    public function ensureStatic(string $varName): Variable
    {
        if (!isset($this->staticVars[$varName])) {
            $this->staticVars[$varName] = new Variable(Variable::TYPE_NULL);
        }

        return $this->staticVars[$varName];
    }

    public function isStaticInitialized(string $varName): bool
    {
        return isset($this->staticInitialized[$varName]);
    }

    public function markStaticInitialized(string $varName): void
    {
        $this->staticInitialized[$varName] = true;
    }

    /** @return list<Variable> */
    public function staticRootsForCycleCollector(): array
    {
        return array_values($this->staticVars);
    }

    public static function fromWrappedFunc(Func $func): self
    {
        $stub = new Func\PHP('{closure}', new Block(null));
        $state = new self($stub);
        $state->wrappedFunc = $func;
        if ($func instanceof Func\PHP) {
            $state->applyDefinitionSite(null, $func->block);
        }

        return $state;
    }

    public static function fromMethodCallable(Func $func, Variable $receiver, string $methodName): self
    {
        $stub = new Func\PHP('{closure}', new Block(null));
        $state = new self($stub);
        $state->wrappedFunc = $func;
        $state->methodReceiver = $receiver;
        $state->methodName = $methodName;
        if ($func instanceof Func\PHP) {
            $state->applyDefinitionSite(null, $func->block);
        }

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
        foreach ($this->staticVars as $name => $var) {
            $clone->staticVars[$name] = $this->copyVar($var);
        }
        $clone->staticInitialized = $this->staticInitialized;
        $clone->definitionFile = $this->definitionFile;
        $clone->definitionLine = $this->definitionLine;

        return $clone;
    }

    public function applyDefinitionSite(?SourceLocation $location, ?Block $body): void
    {
        if (null !== $location) {
            if ('' !== $location->filename) {
                $this->definitionFile = $location->filename;
            }
            if ($location->startLine > 0) {
                $this->definitionLine = $location->startLine;
            }
        }
        if ('' === $this->definitionFile && null !== $body) {
            $path = $body->scriptPath();
            if ('' !== $path) {
                $this->definitionFile = $path;
            }
        }
        if (0 === $this->definitionLine && null !== $body?->func) {
            $line = $body->func->getLine();
            if ($line > 0) {
                $this->definitionLine = $line;
            }
        }
    }

    /**
     * @return array<string, Variable>
     */
    public function debugInfoEntries(): array
    {
        if (!$this->isUserClosure()) {
            return $this->fakeClosureDebugInfoEntries();
        }

        $entries = [];
        $name = new Variable();
        $name->string($this->debugDisplayName());
        $entries['name'] = $name;
        if ('' !== $this->definitionFile) {
            $file = new Variable();
            $file->string($this->definitionFile);
            $entries['file'] = $file;
        }
        if ($this->definitionLine > 0) {
            $line = new Variable();
            $line->int($this->definitionLine);
            $entries['line'] = $line;
        }

        return $entries;
    }

    private function debugDisplayName(): string
    {
        $name = $this->func->getName();
        if (preg_match('/^\{anonymous\}#\d+$/', $name)) {
            return '{closure}';
        }

        return $name;
    }

    /**
     * @return array<string, Variable>
     */
    private function fakeClosureDebugInfoEntries(): array
    {
        $wrapped = $this->wrappedFunc ?? $this->func;
        $label = $wrapped->getName();
        if (null !== $this->methodName && '' !== $this->methodName) {
            $scope = $this->boundScopeClass ?? '';
            if ('' !== $scope) {
                $label = $scope.'::'.$this->methodName;
            }
        }
        $function = new Variable();
        $function->string($label);
        return ['function' => $function];
    }

    public function isUserClosure(): bool
    {
        return null === $this->wrappedFunc && null === $this->methodName;
    }

    /** Zend zend_closure_bind(): static closures cannot receive a bound $this. */
    public function isStaticClosure(): bool
    {
        $compilerFunc = $this->wrappedFunc ?? $this->func;
        if (!$compilerFunc instanceof Func\PHP) {
            return false;
        }
        $cfgFunc = $compilerFunc->block->func ?? null;
        if (null === $cfgFunc) {
            return false;
        }

        return (($cfgFunc->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0;
    }

    public static function register(Context $ctx): void
    {
        $entry = new ClassEntry('Closure');
        $pubStatic = \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC;
        $entry->methods['fromcallable'] = new Builtin\ClosureFromCallable();
        $entry->methodVisibility['fromcallable'] = $pubStatic;
        $entry->methodNames['fromcallable'] = 'fromCallable';
        $entry->methods['fromstatic'] = new Builtin\ClosureFromStatic();
        $entry->methodVisibility['fromstatic'] = $pubStatic;
        $entry->methodNames['fromstatic'] = 'fromStatic';
        $entry->methods['bind'] = new Builtin\ClosureBind();
        $entry->methodVisibility['bind'] = $pubStatic;
        $entry->methodNames['bind'] = 'bind';
        $entry->methods['bindto'] = new Builtin\ClosureBindTo();
        $entry->methodVisibility['bindto'] = $pubStatic;
        $entry->methodNames['bindto'] = 'bindTo';
        $entry->methods['call'] = new Builtin\ClosureCall();
        // Instance-only in Zend (zend_closures.stub.php); static Closure::call() must Error (#7144).
        $entry->methodVisibility['call'] = \PHPCfg\Func::FLAG_PUBLIC;
        $entry->methodNames['call'] = 'call';
        $entry->methods['__debuginfo'] = new Builtin\ClosureDebugInfo();
        $entry->methodVisibility['__debuginfo'] = \PHPCfg\Func::FLAG_PUBLIC;
        $entry->methodNames['__debuginfo'] = '__debugInfo';
        $entry->methods['getusedvariables'] = new Builtin\ClosureGetUsedVariables();
        $entry->methodVisibility['getusedvariables'] = \PHPCfg\Func::FLAG_PUBLIC;
        $entry->methodNames['getusedvariables'] = 'getUsedVariables';
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
