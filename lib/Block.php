<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

// used as a property type.
// @phan-suppress-next-line PhanUnreferencedUseNormal
use PHPCfg\Func;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as VarOperand;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ScriptStack;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

class Block { 

    /**
     * @var OpCode[] $opCodes
     */
    public array $opCodes = [];

    public array $blocks = [];

    /** @var Block[] CFG parent blocks (filled during compilation) */
    public array $parents = [];

    public int $nOpCodes = 0;

    public ?Func $func = null;

    public ?CfgBlock $orig;

    private \SplObjectStorage $scope;

    /** 
     * @var Variable[] $constants
     */
    public array $constants = [];

    public \SplObjectStorage $args;

    public ?Handler $handler = null;

    /** When true, unresolved local reads in child frames become undefined (isset chains). */
    public bool $inheritUndefinedLocals = false;

    /** Synthetic ?? branch block: shares php-cfg orig but is not the function CFG entry (#99). */
    public bool $syntheticCfgBranch = false;

    /** File-level declare(strict_types=1) for this function body (issue #156). */
    public bool $strictTypes = false;

    /** @var array<int, int> scope slot index => Variable::TYPE_* for typed parameters */
    public array $paramTypeConstraints = [];

    /** @var array<int, GenericArrayTypeSpec> generic list/array parameter types (#3705) */
    public array $paramGenericArrayTypeSpecs = [];

    /** @var array<int, list<string>> */
    public array $paramIntersectionConstraints = [];

    /** @var array<int, Op\Type> declared parameter types for reflection (#3355). */
    public array $paramDeclaredTypes = [];

    /** Declared return type AST for reflection (#3355), or null when untyped. */
    public ?Op\Type $returnDeclaredType = null;

    /** Declared scalar return type for this function (issue #205), or null when untyped. */
    public ?int $returnTypeConstraint = null;

    /** Declared `: void` return — non-null returns are rejected. */
    public bool $returnTypeVoid = false;

    /** Declared `: never` return — any return is rejected (issue #1358). */
    public bool $returnTypeNever = false;

    /** Declared `: static` return — late-bound object type (issue #3412). */
    public bool $returnTypeStatic = false;

    /** Parameter index (0-based, excluding $this) that receives a packed trailing-arg array (#197). */
    public ?int $variadicParamIndex = null;

    /** Declared parameter names by index (issue #168). */
    public array $paramNames = [];

    /** Parameter indices declared with `&$param` (issue #140). */
    public array $paramByRef = [];

    /** Function body contains `yield` (issue #167). */
    public bool $isGenerator = false;

    /** Closure `use ($var)` slots populated at call from {@see ClosureState} (issue #72). */
    public array $closureCaptureSlots = [];

    /** Closure `use (&$var)` slots that alias enclosing storage at call (issue #72). */
    public array $closureCaptureByRef = [];

    /** Resolved absolute paths for TYPE_INCLUDE opcodes (arg3 index, issue #54). */
    public array $literalIncludePaths = [];

    /**
     * phpc_deploy_path() + suffix includes (arg3 index, issue #623).
     *
     * @var array<int, array{rel: string, fallback: string, suffix: string, compile: ?string}>
     */
    public array $deployIncludePaths = [];

    /** Absolute entry script path when CFG filename attribute is missing (issue #707). */
    private string $scriptPathOverride = '';

    public function __construct(?CfgBlock $block) {
        $this->orig = $block;
        $this->scope = new \SplObjectStorage;
        $this->args = new \SplObjectStorage;
    }

    public function setScriptPath(string $path): void
    {
        $this->scriptPathOverride = ScriptStack::normalize($path);
    }

    /** Absolute path of the PHP source unit for this block (issue #707). */
    public function scriptPath(): string
    {
        if ('' !== $this->scriptPathOverride) {
            return $this->scriptPathOverride;
        }
        if (null !== $this->func) {
            $file = $this->func->getFile();
            if ('' !== $file && 'unknown' !== $file) {
                return ScriptStack::normalize($file);
            }
        }
        if (null !== $this->orig) {
            foreach ($this->orig->children as $child) {
                if ($child instanceof Op) {
                    $file = $child->getFile();
                    if ('' !== $file && 'unknown' !== $file) {
                        return ScriptStack::normalize($file);
                    }
                }
            }
        }

        return '';
    }

    /** File-level {main} body (not a named function or method). */
    public function isMainScript(): bool
    {
        return null !== $this->func
            && null === $this->func->class
            && '{main}' === $this->func->name;
    }

    public function getOperand(int $offset): Operand {
        foreach ($this->scope as $operand) {
            if ($this->scope[$operand] === $offset) {
                return $operand;
            }
        }
    }

    /**
     * @return list<Operand>
     */
    public function scopedOperands(): array
    {
        $operands = [];
        foreach ($this->scope as $operand) {
            $operands[] = $operand;
        }

        return $operands;
    }

    /**
     * @return list<Operand>
     */
    public function argOperands(): array
    {
        $operands = [];
        foreach ($this->args as $operand) {
            $operands[] = $operand;
        }

        return $operands;
    }

    /**
     * Opcode sub-sequence for class property `new` defaults (issue #3391).
     *
     * @param list<OpCode> $opCodes
     */
    public function fragmentForOpcodes(array $opCodes): Block
    {
        $frag = new Block(null);
        $frag->opCodes = $opCodes;
        $frag->nOpCodes = count($opCodes);
        $frag->constants = $this->constants;
        foreach ($this->scope as $operand) {
            $frag->scope[$operand] = $this->scope[$operand];
        }

        return $frag;
    }

    public function getVarSlot(Operand $operand, bool $isRead): int {
        if ($this->scope->contains($operand)) {
            if ($isRead && null !== self::resolveVariableName($operand)) {
                $this->args[$operand] = $this->scope[$operand];
            }

            return $this->scope[$operand];
        }
        $name = self::resolveVariableName($operand);
        if (null !== $name) {
            $existing = $this->slotIndexForVariableName($name);
            if (null !== $existing) {
                $this->scope[$operand] = $existing;
                if ($isRead) {
                    $this->args[$operand] = $existing;
                }

                return $existing;
            }
        }
        $cfgVar = self::cfgVarRoot($operand);
        if (null !== $cfgVar) {
            foreach ($this->scope as $scopedOp) {
                if (self::cfgVarRoot($scopedOp) === $cfgVar) {
                    $existing = $this->scope[$scopedOp];
                    $this->scope[$operand] = $existing;
                    if ($isRead) {
                        $this->args[$operand] = $existing;
                    }

                    return $existing;
                }
            }
        }
        $next = $this->nextScopeSlot();
            $this->scope[$operand] = $next;
            if ($isRead) {
                $this->args[$operand] = $next;
            }

        return $this->scope[$operand];
    }

    /** Next unused scope slot (SplObjectStorage::count() can collide after inheritScopeFrom, #1058). */
    private function nextScopeSlot(): int
    {
        $next = 0;
        foreach ($this->scope as $op) {
            $next = max($next, $this->scope[$op] + 1);
        }

        return $next;
    }

    public function registerConstant(Operand $operand, Variable $const): int {
        $slot = $this->getVarSlot($operand, false);
        $this->constants[$slot] = $const;
        return $slot;
    }

    /**
     * Copy cfg Var root slot mappings from a sibling branch (?: / if merge, #3790).
     */
    public function inheritCfgVarSlotsFrom(Block $sibling): void
    {
        foreach ($sibling->eachCfgVarRootSlot() as [$root, $slot]) {
            if ($this->scope->contains($root)) {
                continue;
            }
            $this->scope[$root] = $slot;
            if ($sibling->args->contains($root) || $sibling->isArgSlot($slot)) {
                $this->args[$root] = $slot;
            }
        }
    }

    /**
     * @return iterable<array{0: VarOperand, 1: int}>
     */
    public function eachCfgVarRootSlot(): iterable
    {
        $seenRoots = [];
        foreach ($this->scope as $operand) {
            $root = self::cfgVarRoot($operand);
            if (null === $root) {
                continue;
            }
            $rootId = spl_object_id($root);
            if (isset($seenRoots[$rootId])) {
                continue;
            }
            $seenRoots[$rootId] = true;
            yield [$root, $this->scope[$operand]];
        }
    }

    /** Pre-bind a cfg Var root before lowering branch assigns (#3790). */
    public function prebindCfgVarRoot(VarOperand $root, int $slot): void
    {
        if (!$this->scope->contains($root)) {
            $this->scope[$root] = $slot;
        }
    }

    private function isArgSlot(int $slot): bool
    {
        foreach ($this->args as $op) {
            if ($this->args[$op] === $slot) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copy variable slot mappings from a parent block (for synthetic CFG branches).
     */
    public function inheritScopeFrom(Block $parent): void
    {
        foreach ($parent->scope as $operand) {
            if ($this->scope->contains($operand)) {
                continue;
            }
            $slot = $parent->scope[$operand];
            $this->scope[$operand] = $slot;
            if ($parent->args->contains($operand)) {
                $this->args[$operand] = $slot;
            }
            if (isset($parent->constants[$slot]) && !isset($this->constants[$slot])) {
                $this->constants[$slot] = $parent->constants[$slot];
            }
        }
        // literal/deploy include path tables are per-block; inheriting parent paths breaks
        // arg3 indices and can recurse into the wrong TU (layout vs partial, issue #784).
        if (null !== $parent->func) {
            $this->func = $parent->func;
            $this->strictTypes = $parent->strictTypes;
            $this->returnTypeConstraint = $parent->returnTypeConstraint;
            $this->returnTypeVoid = $parent->returnTypeVoid;
            $this->returnTypeNever = $parent->returnTypeNever;
            $this->returnTypeStatic = $parent->returnTypeStatic;
            $this->returnDeclaredType = $parent->returnDeclaredType;
            $this->paramDeclaredTypes = $parent->paramDeclaredTypes;
            $this->paramTypeConstraints = $parent->paramTypeConstraints;
            $this->paramIntersectionConstraints = $parent->paramIntersectionConstraints;
            $this->paramNames = $parent->paramNames;
        }
    }

    public function addOpCode(OpCode ...$ops): void {
        foreach ($ops as $op) {
            $this->nOpCodes++;
            $this->opCodes[] = $op;
        }
    }

    /** True when this function body contains `global $name` (issue #100). */
    public function declaresGlobalName(string $name): bool
    {
        foreach ($this->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_GLOBAL !== $op->type) {
                continue;
            }
            if (!isset($this->constants[$op->arg2])) {
                continue;
            }
            if ($this->constants[$op->arg2]->toString() === $name) {
                return true;
            }
        }

        return false;
    }

    public function findSlot(Operand $op, Frame $frame): ?Variable {
        $byName = self::findVariableInParentFrames($op, $frame);
        if (null !== $byName) {
            return $byName;
        }
        if (!$this->scope->contains($op)) {
            if (!is_null($frame->parent)) {
                return $frame->parent->block->findSlot($op, $frame->parent);
            }

            return null;
        }
        $idx = $this->scope[$op];

        return $frame->scope[$idx] ?? null;
    }

    public function slotIndexForVariableName(string $name): ?int
    {
        foreach ($this->scope as $operand) {
            if (self::resolveVariableName($operand) === $name) {
                return $this->scope[$operand];
            }
        }

        return null;
    }

    /**
     * @return iterable<array{0: string, 1: int}> variable name and scope slot
     */
    public function eachNamedScopeSlot(): iterable
    {
        $seen = [];
        foreach ($this->scope as $operand) {
            $slot = $this->scope[$operand];
            if (isset($seen[$slot])) {
                continue;
            }
            $seen[$slot] = true;
            $name = self::resolveVariableName($operand);
            if (null === $name) {
                continue;
            }
            yield [$name, $slot];
        }
    }

    public function slotForOperand(Operand $operand): ?int
    {
        if ($this->scope->contains($operand)) {
            return $this->scope[$operand];
        }

        return null;
    }

    public function operandForScopeSlot(int $slot): ?Operand
    {
        foreach ($this->scope as $operand) {
            if ($this->scope[$operand] === $slot) {
                return $operand;
            }
        }

        return null;
    }

    /**
     * Resolve a local by runtime name for variable variables (`$$name`, issue #1226).
     */
    public function findVariableByRuntimeName(string $name, Frame $frame): ?Variable
    {
        $found = self::findVariableInParentFramesByName($name, $frame);
        if (null !== $found) {
            return $found;
        }
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if ($f->block === $this && isset($f->dynamicLocals[$name])) {
                return $f->dynamicLocals[$name];
            }
        }

        return null;
    }

    /**
     * Create or return a writable local for `$$name = …` when the resolved name has no slot yet (#3801).
     */
    public function ensureVariableByRuntimeName(string $name, Frame $frame): Variable
    {
        $found = $this->findVariableByRuntimeName($name, $frame);
        if (null !== $found) {
            return $found;
        }
        $idx = $this->slotIndexForVariableName($name);
        if (null !== $idx) {
            if (!isset($frame->scope[$idx])) {
                $frame->scope[$idx] = new Variable();
            }

            return $frame->scope[$idx];
        }
        if (!isset($frame->dynamicLocals[$name])) {
            $frame->dynamicLocals[$name] = new Variable();
        }

        return $frame->dynamicLocals[$name];
    }

    /**
     * Zend include/require: included file shares caller locals by name (issue #471).
     */
    private static function findVariableInParentFrames(Operand $op, Frame $frame): ?Variable
    {
        $name = self::resolveVariableName($op);
        // php-cfg merge temporaries use empty Var names; do not match other "" slots (#3790).
        if (null === $name || '' === $name) {
            return null;
        }

        return self::findVariableInParentFramesByName($name, $frame);
    }

    public static function findVariableInParentFramesByName(string $name, Frame $frame): ?Variable
    {
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if ('this' === $name && !empty($f->calledArgs)) {
                return $f->calledArgs[0];
            }
            if (null === $f->block) {
                continue;
            }
            $idx = $f->block->slotIndexForVariableName($name);
            if (null !== $idx && isset($f->scope[$idx])) {
                return $f->scope[$idx];
            }
            if (isset($f->dynamicLocals[$name])) {
                return $f->dynamicLocals[$name];
            }
        }

        return null;
    }

    public function getFrame(Context $context, ?Frame $frame = null): Frame {
        // Back-edge to the same block (goto label) must reuse the frame; otherwise each
        // jump chains a new parent Frame and getFrame never finishes (issue #1228).
        if (null !== $frame && $this === $frame->block) {
            $frame->pos = 0;

            return $frame;
        }

        // Todo: build scope
        $scope = [];
        $cfgMerge = count($this->parents) > 1;
        $scopeSize = $this->scope->count();
        foreach ($this->scope as $op) {
            $pos = $this->scope[$op];
            // php-cfg may register the same slot under multiple Operand keys (#1885).
            // Variable reads in args must still resolve (#3787 merge + literal arm).
            if (isset($scope[$pos]) && !$this->args->contains($op)) {
                continue;
            }
            if (null !== $frame && 'this' === self::resolveVariableName($op)) {
                if (!empty($frame->callArgs)) {
                    $scope[$pos] = $frame->callArgs[0];
                    continue;
                }
                if (!empty($frame->calledArgs)) {
                    $scope[$pos] = $frame->calledArgs[0];
                    continue;
                }
            }

            if (isset($this->constants[$pos]) && !$this->args->contains($op)) {
                $scope[$pos] = $this->constants[$pos];
            } elseif (isset($this->closureCaptureSlots[$pos])) {
                $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
            } elseif ($this->args->contains($op)) {
                // Callee parameters are filled by TYPE_ARG_RECV; do not inherit caller locals (#3803).
                if ($this->isArgRecvParameterSlot($pos)) {
                    $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
                    continue;
                }
                if (is_null($frame)) {
                    $scope[$pos] = self::initialEntryVariable($op, $context, $pos, $this);
                    continue;
                }
                // {main} top-level names always live in the global table (#3601, #3787).
                if (self::usesMainScriptGlobalSlot($op, $this)) {
                    $scope[$pos] = self::initialEntryVariable($op, $context, $pos, $this);
                    continue;
                }
                $found = false;
                // Resolve reads from the jump parent block, not the merge block's scope (#3787).
                $parent = $frame->block->findSlot($op, $frame);
                if (!is_null($parent)) {
                    $scope[$pos] = $parent;
                    $found = true;
                }
                if (!$found) {
                    $inherited = self::findVariableInParentFrames($op, $frame);
                    if (null !== $inherited) {
                        $scope[$pos] = $inherited;
                        continue;
                    }
                }
                if (!$found) {
                    $name = self::resolveVariableName($op);
                    if (null !== $name && Superglobals::isSuperglobalName($name)) {
                        $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
                        continue;
                    }
                    if (null !== $name && $this->declaresGlobalName($name)) {
                        $local = new Variable(Variable::TYPE_NULL);
                        $local->indirect($context->ensureGlobal($name));
                        $scope[$pos] = $local;
                        continue;
                    }
                    if ($this->inheritUndefinedLocals) {
                        $scope[$pos] = new Variable(Variable::TYPE_UNDEFINED);
                        continue;
                    }
                    throw new \LogicException("Could not resolve argument");
                }
            } else {
                if (null !== $frame) {
                    $inherited = self::findVariableInParentFrames($op, $frame);
                    if (null !== $inherited) {
                        $scope[$pos] = $inherited;
                        continue;
                    }
                    if ($cfgMerge) {
                        $fromJump = $this->findSlot($op, $frame);
                        if (null !== $fromJump) {
                            $scope[$pos] = $fromJump;
                            continue;
                        }
                    }
                }
                if (
                    $this->inheritUndefinedLocals
                    && null !== $frame
                    && isset($frame->scope[$pos])
                ) {
                    $scope[$pos] = $frame->scope[$pos];
                } else {
                    $name = self::resolveVariableName($op);
                    if (null !== $name && $this->declaresGlobalName($name)) {
                        $local = new Variable(Variable::TYPE_NULL);
                        $local->indirect($context->ensureGlobal($name));
                        $scope[$pos] = $local;
                    } elseif (null === $frame || self::usesMainScriptGlobalSlot($op, $this)) {
                        // {main} locals live in the global table on every CFG block (#3601, #3787).
                        $scope[$pos] = self::initialEntryVariable($op, $context, $pos, $this);
                    } else {
                        $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
                    }
                }
            }
        }

        // Sparse slot indices must preserve keys; variadic spread reindexes (#137).
        $return = new Frame(null, $this, $frame);
        $return->scope = $scope;
        $return->scriptPath = $this->scriptPath();
        if (!is_null($frame) && !is_null($frame->returnVar)) {
            $return->returnVar = $frame->returnVar;
        }
        return $return;
    }

    /** Top-level script variable (not superglobal) — always indirect through global table (#3787). */
    private static function usesMainScriptGlobalSlot(Operand $op, self $block): bool
    {
        if (!$block->isMainScript()) {
            return false;
        }
        $name = self::resolveVariableName($op);

        return null !== $name && !Superglobals::isSuperglobalName($name);
    }

    /**
     * Entry-frame locals for {main}: top-level script variables live in the global symbol table (#3601).
     */
    private static function initialEntryVariable(
        Operand $op,
        Context $context,
        int $slot,
        self $block
    ): Variable {
        $name = self::resolveVariableName($op);
        if (null !== $name && $block->isMainScript() && !Superglobals::isSuperglobalName($name)) {
            $local = new Variable(Variable::TYPE_NULL);
            $local->indirect($context->ensureGlobal($name));
            if (isset($block->paramTypeConstraints[$slot])) {
                $local->resolveIndirect()->typeConstraint = $block->paramTypeConstraints[$slot];
            }
            if (isset($block->paramGenericArrayTypeSpecs[$slot])) {
                $local->resolveIndirect()->genericArrayTypeSpec = $block->paramGenericArrayTypeSpecs[$slot];
            }

            return $local;
        }

        return self::initialVariableForOperand($op, $context, $slot, $block);
    }

    private static function initialVariableForOperand(
        Operand $op,
        Context $context,
        int $slot,
        self $block
    ): Variable {
        $name = self::resolveVariableName($op);
        if (null !== $name && Superglobals::isSuperglobalName($name)) {
            $existing = $context->getSuperglobal($name);
            if (null !== $existing) {
                return $existing;
            }

            return $context->ensureSuperglobal($name);
        }

        $var = new Variable(Variable::TYPE_NULL);
        if (isset($block->paramTypeConstraints[$slot])) {
            $var->typeConstraint = $block->paramTypeConstraints[$slot];
        }
        if (isset($block->paramGenericArrayTypeSpecs[$slot])) {
            $var->genericArrayTypeSpec = $block->paramGenericArrayTypeSpecs[$slot];
        }

        return $var;
    }

    /**
     * Call result temporary is returned by a Terminal_Return in this block (php-cfg often omits usages).
     */
    public function callResultFeedsReturn(Operand $result): bool
    {
        $resultRoot = self::cfgVarRoot($result);
        if (null === $resultRoot) {
            return false;
        }
        foreach ($this->orig->children as $child) {
            if (!$child instanceof Op\Terminal\Return_) {
                continue;
            }
            if (null === $child->expr) {
                continue;
            }
            if (self::cfgVarRoot($child->expr) === $resultRoot) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg may wrap the same Var in distinct Operand objects (e.g. call result vs return expr).
     */
    public static function cfgVarRoot(Operand $op): ?VarOperand
    {
        while ($op instanceof Temporary) {
            if (null === $op->original) {
                return null;
            }
            $op = $op->original;
        }

        return $op instanceof VarOperand ? $op : null;
    }

    /** Scope slot receiving TYPE_ARG_RECV (function parameter, not caller local). */
    private function isArgRecvParameterSlot(int $slot): bool
    {
        foreach ($this->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV === $op->type && (int) $op->arg1 === $slot) {
                return true;
            }
        }

        return false;
    }

    public static function resolveVariableName(Operand $op): ?string
    {
        $root = self::cfgVarRoot($op);
        if (null === $root) {
            return null;
        }
        $nameOp = $root->name;
        if (!$nameOp instanceof Literal) {
            return null;
        }
        if (null !== $nameOp->type && Variable::mapFromType($nameOp->type) !== Variable::TYPE_STRING) {
            return null;
        }
        if (!is_string($nameOp->value)) {
            return null;
        }

        return $nameOp->value;
    }

    /**
     * @param int ...$types OpCode::TYPE_* values to match
     */
    public static function containsOpcodeTypes(?self $root, int ...$types): bool
    {
        if (null === $root || [] === $types) {
            return false;
        }
        $want = array_fill_keys($types, true);
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (isset($want[$op->type])) {
                    return true;
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof self) {
                        $stack[] = $sub;
                    }
                }
            }
        }

        return false;
    }

    /** Script or nested function body contains `yield` / `yield from` (issue #167). */
    public static function containsGeneratorOpcodes(?self $root): bool
    {
        return self::containsOpcodeTypes(
            $root,
            OpCode::TYPE_YIELD,
            OpCode::TYPE_YIELD_FROM
        );
    }

    /** Script contains try/catch/finally/throw opcodes (#2114). IR may verify; MCJIT execute is not yet safe. */
    public static function containsExceptionHandlingOpcodes(?self $root): bool
    {
        return self::containsOpcodeTypes(
            $root,
            OpCode::TYPE_TRY,
            OpCode::TYPE_CATCH,
            OpCode::TYPE_FINALLY,
            OpCode::TYPE_THROW,
            OpCode::TYPE_RETHROW
        );
    }

    /** Script contains `finally` — JIT lowering still VM-fallback until #2114 phase B. */
    public static function containsFinallyOpcodes(?self $root): bool
    {
        return self::containsOpcodeTypes($root, OpCode::TYPE_FINALLY);
    }

    /**
     * User class implements ArrayAccess and uses $obj[$key] — VM-only until JIT lowering (#3331).
     */
    public static function containsArrayAccessObjectOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        $hasArrayAccessClass = false;
        $hasObjectDimFetch = false;
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (
                    OpCode::TYPE_DECLARE_CLASS === $op->type
                    && in_array('arrayaccess', $op->classImplements, true)
                ) {
                    $hasArrayAccessClass = true;
                }
                if (
                    in_array($op->type, [OpCode::TYPE_ARRAY_DIM_FETCH, OpCode::TYPE_ARRAY_DIM_FETCH_WRITE], true)
                    && null !== $op->arg3
                ) {
                    $hasObjectDimFetch = true;
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof self) {
                        $stack[] = $sub;
                    }
                }
            }
        }

        return $hasArrayAccessClass && $hasObjectDimFetch;
    }

    /**
     * CFG regions that MCJIT must not execute yet; `bin/jit.php` runs the VM instead (#2114, #167).
     * Simple try/catch without `finally` may pass MCJIT when {@see TryCatchJitExecuteTest} is green.
     */
    public static function requiresVmLowering(?self $root): bool
    {
        return self::containsGeneratorOpcodes($root)
            || self::containsFinallyOpcodes($root)
            || self::containsExceptionHandlingOpcodes($root)
            || self::containsArrayAccessObjectOpcodes($root);
    }
}
