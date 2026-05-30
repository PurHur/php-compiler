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

    /** @var array<int, list<string>> */
    public array $paramIntersectionConstraints = [];

    /** Declared scalar return type for this function (issue #205), or null when untyped. */
    public ?int $returnTypeConstraint = null;

    /** Declared `: void` return — non-null returns are rejected. */
    public bool $returnTypeVoid = false;

    /** Declared `: never` return — any return is rejected (issue #1358). */
    public bool $returnTypeNever = false;

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

    public function getVarSlot(Operand $operand, bool $isRead): int {
        if (!$this->scope->contains($operand)) {
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
        return self::findVariableInParentFramesByName($name, $frame);
    }

    /**
     * Zend include/require: included file shares caller locals by name (issue #471).
     */
    private static function findVariableInParentFrames(Operand $op, Frame $frame): ?Variable
    {
        $name = self::resolveVariableName($op);
        if (null === $name) {
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
            if (isset($scope[$pos])) {
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

            if (isset($this->constants[$pos])) {
                $scope[$pos] = $this->constants[$pos];
            } elseif (isset($this->closureCaptureSlots[$pos])) {
                $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
            } elseif ($this->args->contains($op)) {
                if (is_null($frame)) {
                    $scope[$pos] = self::initialEntryVariable($op, $context, $pos, $this);
                    continue;
                }
                $found = false;
                $parent = $cfgMerge
                    ? $this->findSlot($op, $frame)
                    : $frame->block->findSlot($op, $frame);
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
                    } elseif (null === $frame) {
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
    private static function cfgVarRoot(Operand $op): ?VarOperand
    {
        while ($op instanceof Temporary) {
            if (null === $op->original) {
                return null;
            }
            $op = $op->original;
        }

        return $op instanceof VarOperand ? $op : null;
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

    /**
     * Top-level script scope only (skips nested TYPE_FUNCDEF bodies; issue #3074).
     * Used so bin/jit.php can MCJIT the main script while generator bodies use resume lowering.
     */
    public static function containsGeneratorOpcodesInScriptScope(?self $root): bool
    {
        return self::containsOpcodeTypesSkippingFuncDefs(
            $root,
            OpCode::TYPE_YIELD,
            OpCode::TYPE_YIELD_FROM
        );
    }

    /**
     * @param int ...$types OpCode::TYPE_* values to match
     */
    private static function containsOpcodeTypesSkippingFuncDefs(?self $root, int ...$types): bool
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
                        if (OpCode::TYPE_FUNCDEF === $op->type && $sub === $op->block1) {
                            continue;
                        }
                        $stack[] = $sub;
                    }
                }
            }
        }

        return false;
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
     * CFG regions that MCJIT must not execute yet; `bin/jit.php` runs the VM instead (#2114, #167).
     * Simple try/catch without `finally` may pass MCJIT when {@see TryCatchJitExecuteTest} is green.
     */
    public static function requiresVmLowering(?self $root): bool
    {
        return self::containsGeneratorOpcodesInScriptScope($root)
            || self::containsFinallyOpcodes($root)
            || self::containsExceptionHandlingOpcodes($root);
    }
}
