<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\CompilerVersion;
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

    /**
     * Closure scope (ce) for private/protected / self:: / getClosureScopeClass().
     * Distinct from late-static called_scope ({@see $boundCalledScopeClass}).
     */
    public ?string $boundScopeClass = null;

    /**
     * Late-static called_scope when $this is unbound (e.g. closure created in a static
     * method). When {@see $boundThis} is set, called_scope is derived from that object.
     */
    public ?string $boundCalledScopeClass = null;

    /** Definition site for var_dump via get_debug_info handler (issue #7069, #22565). */
    public string $definitionFile = '';

    public int $definitionLine = 0;

    /** Owning Closure object for Closure::getCurrent() (#13981, Zend/zend_closures.c). */
    public ?ObjectEntry $ownerObject = null;

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
        // Closure-body statics share the same frame-teardown hazard as Context statics (#28039).
        $this->staticVars[$varName]->functionStaticStorage = true;

        return $this->staticVars[$varName];
    }

    public function peekStatic(string $varName): ?Variable
    {
        return $this->staticVars[$varName] ?? null;
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

    /**
     * Fake static closure for missing method + __callStatic (FCC / fromCallable, #25757).
     *
     * {@see \PHPCompiler\VM::initClosureCall} sets magicCallMethodName from {@see $methodName}
     * then invokes {@see $callStatic} (__callStatic).
     */
    public static function fromMagicStaticCallable(
        Func $callStatic,
        string $methodName,
        string $scopeClass
    ): self {
        $stub = new Func\PHP('{closure}', new Block(null));
        $state = new self($stub);
        $state->wrappedFunc = $callStatic;
        $state->methodName = $methodName;
        $state->methodReceiver = null;
        $state->boundScopeClass = $scopeClass;
        if ($callStatic instanceof Func\PHP) {
            $state->applyDefinitionSite(null, $callStatic->block);
        }

        return $state;
    }

    /**
     * Language {@code clone $closure} — duplicate static table (Zend zend_array_dup, #23489).
     *
     * Same shape as {@see cloneForBind()} for statics/captures; kept separate so bind and
     * object-clone call sites stay explicit.
     */
    public function cloneForObjectClone(): self
    {
        return $this->cloneForBind();
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
        $clone->wrappedFunc = $this->wrappedFunc;
        $clone->methodName = $this->methodName;
        $clone->methodReceiver = null !== $this->methodReceiver
            ? $this->copyVar($this->methodReceiver)
            : null;
        $clone->boundThis = null !== $this->boundThis ? $this->copyVar($this->boundThis) : null;
        $clone->boundScopeClass = $this->boundScopeClass;
        $clone->boundCalledScopeClass = $this->boundCalledScopeClass;
        // Value-copy statics at clone/bind time; tables then diverge (zend_array_dup).
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
     * Zend zend_closure_get_debug_info handler bag — not a user-visible Closure method (#22565, #24521).
     *
     * php-src Zend/zend_closures.c: parameter bag (`$name` / `&$name` => `"<required>"` /
     * `"<optional>"`) on every profile when the closure has args or is variadic. name/file/line
     * are PHP 8.4+ only ({@see CompilerVersion::supportsClosureRichDebugInfo()}).
     *
     * @return array<string, Variable>
     */
    public function debugInfoEntries(): array
    {
        if (!$this->isUserClosure()) {
            return $this->fakeClosureDebugInfoEntries();
        }

        $entries = [];

        // PHP 8.4+: name/file/line (Zend always emits these for non-fake user closures).
        if (CompilerVersion::supportsClosureRichDebugInfo()) {
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
        }

        $parameter = $this->parameterDebugInfoEntry($this->func->block);
        if (null !== $parameter) {
            $entries['parameter'] = $parameter;
        }

        return $entries;
    }

    /**
     * Build Zend `parameter` debug hash, or null when there are no declared args (#24521).
     */
    private function parameterDebugInfoEntry(?Block $block): ?Variable
    {
        if (null === $block) {
            return null;
        }
        $paramNames = $block->paramNames;
        $numArgs = \count($paramNames);
        if (0 === $numArgs) {
            return null;
        }

        $required = 0;
        for ($i = 0; $i < $numArgs; ++$i) {
            if ($block->variadicParamIndex === $i || ParamArgumentCountError::parameterHasDefault($block, $i)) {
                break;
            }
            ++$required;
        }

        $ht = new HashTable();
        for ($i = 0; $i < $numArgs; ++$i) {
            $prefix = isset($block->paramByRef[$i]) ? '&' : '';
            $key = $prefix.'$'.$paramNames[$i];
            $info = new Variable();
            $info->string($i >= $required ? '<optional>' : '<required>');
            $ht->addNew($key, $info);
        }

        $parameter = new Variable();
        $parameter->array($ht);

        return $parameter;
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

    /**
     * ZEND_ACC_FAKE_CLOSURE for a non-static method (fromCallable / FCC / getClosure).
     *
     * php-src zend_closures.c: unbind uses "Cannot unbind $this of method" (#23421).
     */
    public function isNonStaticMethodFakeClosure(): bool
    {
        if ($this->isUserClosure() || $this->isStaticClosure()) {
            return false;
        }

        return null !== $this->methodName && '' !== $this->methodName;
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

    /** True when the closure body reads $this (ZEND_ACC_USES_THIS). */
    public function usesThis(): bool
    {
        if ($this->isStaticClosure()) {
            return false;
        }

        return null !== $this->func->block->slotIndexForVariableName('this');
    }

    /**
     * True when this_ptr is set (zend_closures.c !Z_ISUNDEF(closure->this_ptr)).
     *
     * Unbind (bindTo(null)) is rejected only when this is set and {@see usesThis()} (#23387).
     */
    public function hasBoundThis(): bool
    {
        if (null === $this->boundThis) {
            return false;
        }
        $bound = $this->boundThis->resolveIndirect();

        return Variable::TYPE_NULL !== $bound->type;
    }

    public static function register(Context $ctx): void
    {
        $entry = new ClassEntry('Closure');
        $entry->isFinal = true;
        // ZEND_ACC_NO_DYNAMIC_PROPERTIES (zend_closures.c; #26371).
        $entry->noDynamicProperties = true;
        $pubStatic = \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC;
        $entry->methods['fromcallable'] = new Builtin\ClosureFromCallable();
        $entry->methodVisibility['fromcallable'] = $pubStatic;
        $entry->methodNames['fromcallable'] = 'fromCallable';
        if (CompilerVersion::supportsClosureFromStatic()) {
            $entry->methods['fromstatic'] = new Builtin\ClosureFromStatic();
            $entry->methodVisibility['fromstatic'] = $pubStatic;
            $entry->methodNames['fromstatic'] = 'fromStatic';
        }
        $entry->methods['bind'] = new Builtin\ClosureBind();
        $entry->methodVisibility['bind'] = $pubStatic;
        $entry->methodNames['bind'] = 'bind';
        $entry->methods['bindto'] = new Builtin\ClosureBindTo();
        // Instance method in Zend (zend_closures.stub.php). Must not be FLAG_STATIC: after
        // #22288, instance calls of static methods omit the receiver from callArgs, which
        // made $c->bindTo(...) throw LogicException (#22423, regression of #22089).
        $entry->methodVisibility['bindto'] = \PHPCfg\Func::FLAG_PUBLIC;
        $entry->methodNames['bindto'] = 'bindTo';
        $entry->methods['call'] = new Builtin\ClosureCall();
        // Instance-only in Zend (zend_closures.stub.php); static Closure::call() must Error (#7144).
        $entry->methodVisibility['call'] = \PHPCfg\Func::FLAG_PUBLIC;
        $entry->methodNames['call'] = 'call';
        // No Closure::__debugInfo method — Zend uses get_debug_info handler only (#22565, re-#7069).
        if (CompilerVersion::supportsClosureGetUsedVariables()) {
            $entry->methods['getusedvariables'] = new Builtin\ClosureGetUsedVariables();
            $entry->methodVisibility['getusedvariables'] = \PHPCfg\Func::FLAG_PUBLIC;
            $entry->methodNames['getusedvariables'] = 'getUsedVariables';
        }
        if (CompilerVersion::supportsClosureGetCurrent()) {
            $entry->methods['getcurrent'] = new Builtin\ClosureGetCurrent();
            $entry->methodVisibility['getcurrent'] = $pubStatic;
            $entry->methodNames['getcurrent'] = 'getCurrent';
            // Zend/zend_closures.stub.php — getCurrent(): Closure (#28710, re-#22583).
            $getCurrentRet = ReflectionTypeSupport::cfgTypeFromLabel('Closure');
            if (null !== $getCurrentRet) {
                $entry->methodReturnDeclaredTypes['getcurrent'] = $getCurrentRet;
            }
        }
        $ctx->classes['closure'] = $entry;
    }

    public function wrapObject(Context $ctx): ObjectEntry
    {
        $entry = new ObjectEntry($ctx->classes['closure']);
        $entry->closureState = $this;
        $entry->constructed = true;
        $this->ownerObject = $entry;

        return $entry;
    }

    private function copyVar(Variable $src): Variable
    {
        $copy = new Variable();
        $copy->copyFrom($src->resolveIndirect());

        return $copy;
    }
}
