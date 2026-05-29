<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

require_once __DIR__.'/OpCodeNames.php';

use SplObjectStorage;
use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPCfg\Script;
use PHPTypes\Type;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Web\ConstStringFolder;
use PHPCompiler\Web\IncludePathResolver;
use PHPCompiler\Web\Superglobals;

class Compiler {

    protected ?SplObjectStorage $seen = null;
    protected ?SplObjectStorage $funcs = null;

    private ?string $debugLastPhaseInputFile = null;
    private int $debugLastPhaseCounter = 0;
    private ?string $debugLastPhaseKey = null;

    /** Set from the first compile-time abort (#2642, self-host diagnostics). */
    private ?string $compileAbortDetail = null;

    public function setDebugLastPhaseInputFile(?string $filename): void
    {
        $this->debugLastPhaseInputFile = $filename;
    }

    private function debugLastPhaseIsEnabled(): bool
    {
        if (\defined('PHP_COMPILER_DEBUG_LAST_PHASE') && PHP_COMPILER_DEBUG_LAST_PHASE) {
            return true;
        }
        $v = $_SERVER['PHP_COMPILER_DEBUG_LAST_PHASE'] ?? $_ENV['PHP_COMPILER_DEBUG_LAST_PHASE'] ?? getenv('PHP_COMPILER_DEBUG_LAST_PHASE');
        if (false === $v || null === $v || '' === $v) {
            return false;
        }
        $v = strtolower((string) $v);

        return '1' === $v || 'true' === $v || 'yes' === $v;
    }

    private function debugLastPhaseFile(): ?string
    {
        if (\defined('PHP_COMPILER_DEBUG_LAST_PHASE_FILE') && is_string(PHP_COMPILER_DEBUG_LAST_PHASE_FILE) && '' !== PHP_COMPILER_DEBUG_LAST_PHASE_FILE) {
            return PHP_COMPILER_DEBUG_LAST_PHASE_FILE;
        }
        $explicit = $_SERVER['PHP_COMPILER_DEBUG_LAST_PHASE_FILE'] ?? $_ENV['PHP_COMPILER_DEBUG_LAST_PHASE_FILE'] ?? getenv('PHP_COMPILER_DEBUG_LAST_PHASE_FILE');
        if (is_string($explicit) && '' !== $explicit) {
            return $explicit;
        }
        if (is_dir('build')) {
            return 'build/last_lowering_phase.json';
        }

        return null;
    }

    private function debugWriteLastPhase(string $label, ?Block $block = null, mixed $node = null): void
    {
        if (!$this->debugLastPhaseIsEnabled()) {
            return;
        }
        if ('Compiler::compileOps op' === $label) {
            ++$this->debugLastPhaseCounter;
            // Keep stderr/file noise low: sample op breadcrumbs (still frequent enough to localize crash).
            if (0 !== ($this->debugLastPhaseCounter % 200)) {
                return;
            }
        }
        $file = $this->debugLastPhaseFile();

        $funcName = null;
        if (null !== $block && null !== $block->func) {
            $funcName = $block->func->name ?? null;
            if (null !== $block->func->class && isset($block->func->class->name)) {
                $funcName = $block->func->class->name.'::'.((string) $funcName);
            }
        }

        $nodeType = null;
        if (null !== $node) {
            $nodeType = \is_object($node) ? \get_class($node) : \gettype($node);
        }

        $key = ($this->debugLastPhaseInputFile ?? '').'|'.($funcName ?? '').'|'.$label.'|'.($nodeType ?? '');
        if ($key === $this->debugLastPhaseKey) {
            return;
        }
        $this->debugLastPhaseKey = $key;

        $input = $this->debugLastPhaseInputFile;
        if (\defined('PHP_COMPILER_DEBUG_LAST_PHASE_INPUT_FILE') && is_string(PHP_COMPILER_DEBUG_LAST_PHASE_INPUT_FILE) && '' !== PHP_COMPILER_DEBUG_LAST_PHASE_INPUT_FILE) {
            $input = PHP_COMPILER_DEBUG_LAST_PHASE_INPUT_FILE;
        }
        if (
            (null === $input || '' === $input || str_ends_with(str_replace('\\', '/', $input), '/compile_smoke_m3_emit_native_entry.php'))
            && \function_exists('getenv')
        ) {
            $fromSource = getenv('PHP_COMPILER_M3_SOURCE');
            if (is_string($fromSource) && '' !== $fromSource) {
                $input = $fromSource;
            }
        }
        if (
            (null === $input || '' === $input || str_ends_with(str_replace('\\', '/', $input), '/compile_smoke_m3_emit_native_entry.php'))
            && isset($_SERVER['argv'])
            && \is_array($_SERVER['argv'])
            && [] !== $_SERVER['argv']
        ) {
            $last = $_SERVER['argv'][\count($_SERVER['argv']) - 1] ?? null;
            if (is_string($last) && '' !== $last && str_ends_with(strtolower($last), '.php')) {
                $input = $last;
            }
        }

        $payload = [
            'ts' => \microtime(true),
            'input' => $input,
            'func' => $funcName,
            'label' => $label,
            'node' => $nodeType,
        ];

        $line = \json_encode($payload, JSON_UNESCAPED_SLASHES)."\n";
        if (null !== $file && '' !== $file) {
            @\file_put_contents($file, $line, LOCK_EX);
        }
        $stderr = (\defined('PHP_COMPILER_DEBUG_LAST_PHASE_STDERR') && PHP_COMPILER_DEBUG_LAST_PHASE_STDERR)
            ? '1'
            : ($_SERVER['PHP_COMPILER_DEBUG_LAST_PHASE_STDERR'] ?? $_ENV['PHP_COMPILER_DEBUG_LAST_PHASE_STDERR'] ?? getenv('PHP_COMPILER_DEBUG_LAST_PHASE_STDERR'));
        if (false !== $stderr && null !== $stderr && '' !== $stderr && '0' !== $stderr) {
            @\fwrite(STDERR, "last_phase: {$line}");
        }
    }

    public function resetCompileAbortDetail(): void
    {
        $this->compileAbortDetail = null;
    }

    public function getCompileAbortDetail(): ?string
    {
        return $this->compileAbortDetail;
    }

    /**
     * Best-effort set of the first compile abort detail without throwing.
     * Used to surface self-host null-return failure modes (#2666).
     */
    public function setCompileAbortDetailIfEmpty(string $detail): void
    {
        if (null === $this->compileAbortDetail || '' === $this->compileAbortDetail) {
            $this->compileAbortDetail = $detail;
        }
    }

    /**
     * Marks the CFG construct that halted compilation before throwing LogicException (#2642).
     *
     * @return never
     */
    protected function throwCompileLogic(string $detail): void
    {
        if (null === $this->compileAbortDetail) {
            $this->compileAbortDetail = $detail;
        }

        throw new \LogicException($detail);
    }

    /**
     * Like throwCompileLogic for CompileError paths (#2642).
     *
     * @return never
     */
    protected function throwCompileError(string $detail): void
    {
        if (null === $this->compileAbortDetail) {
            $this->compileAbortDetail = $detail;
        }

        throw new \CompileError($detail);
    }

    public function compile(Script $script): ?Block {
        $this->resetCompileAbortDetail();
        $this->seen = new SplObjectStorage;
        $this->debugWriteLastPhase('Compiler::compile enter');

        /** @var mixed $main */
        $main = $this->compileCfgBlock($script->main->cfg, $script->main->params, $script->main);
        if (!$main instanceof Block) {
            // Self-host AOT can surface unexpected stub returns as null; capture a stable diagnostic.
            if (null === $this->compileAbortDetail) {
                $this->compileAbortDetail = 'Compiler::compile: compileCfgBlock returned non-Block';
            }
            $this->seen = null;

            return null;
        }

        $this->seen = null;

        return $main;
    }

    /** M3 emit TU: trivial single-block sources without full seen-map compile (#1937). */
    public function compileEmitSmoke(Script $script): ?Block
    {
        $this->resetCompileAbortDetail();
        // Inventory-scale sources declare user functions and/or class-like units; emit-smoke only needs {main}
        // — same as compile() without a compile() callee in the M3 emit TU (#2633, #2666).
        if ([] !== $script->functions || $this->emitSmokeScriptHasClassLike($script)) {
            $this->seen = new SplObjectStorage;
        }
        $block = $this->compileCfgBlock($script->main->cfg, $script->main->params, $script->main);
        $this->seen = null;
        if (null === $block && null !== $this->compileAbortDetail && '' !== $this->compileAbortDetail) {
            echo 'Compiler::compileEmitSmoke: '.$this->compileAbortDetail."\n";
        }

        return $block;
    }

    /**
     * Emit-smoke is intended for small scripts; class-like constructs are a strong signal that
     * we should run the full compile path (self-host M5, #2666).
     */
    private function emitSmokeScriptHasClassLike(Script $script): bool
    {
        foreach ($script->main->cfg->children as $child) {
            if (
                $child instanceof Op\Stmt\Class_
                || $child instanceof Op\Stmt\Interface_
                || $child instanceof Op\Stmt\Trait_
                || $child instanceof Op\Stmt\Enum_
            ) {
                return true;
            }
        }

        return false;
    }

    public function compileFunc(string $name, CfgFunc $func): Func {
        $this->resetCompileAbortDetail();
        $this->seen = new SplObjectStorage;

        $funcBlock = $this->compileCfgBlock($func->cfg, $func->params, $func);
        $this->seen = null;
        return new Func\PHP($name, $funcBlock);
    }

    protected function applyReturnTypeFromFunc(Block $block, CfgFunc $func): void
    {
        // php-cfg marks file-level {main} as void; only enforce on user functions/methods (#205).
        if ('{main}' === $func->name && null === $func->class) {
            return;
        }
        $returnType = $func->returnType;
        if (null === $returnType) {
            return;
        }
        if ($returnType instanceof Op\Type\Void_) {
            $block->returnTypeVoid = true;

            return;
        }
        if ($returnType instanceof Op\Type\Never_) {
            $block->returnTypeNever = true;

            return;
        }
        if ($returnType instanceof Op\Type\Literal) {
            if ('void' === $returnType->name) {
                $block->returnTypeVoid = true;

                return;
            }
            if ('never' === $returnType->name) {
                $block->returnTypeNever = true;

                return;
            }
            $mapped = Variable::mapFromType(Type::fromDecl($returnType->name));
            if (Variable::TYPE_UNDEFINED !== $mapped) {
                $block->returnTypeConstraint = $mapped;
            }
        }
    }

    /**
     * php-cfg appends a null Terminal_Return after exit(); skip it for :never (issue #1358).
     */
    protected function neverFunctionHasAbnormalExitBeforeReturn(CfgBlock $block, Op\Terminal\Return_ $return): bool
    {
        foreach ($block->children as $child) {
            if ($child === $return) {
                return false;
            }
            if ($child instanceof Op\Expr\Exit_) {
                return true;
            }
        }

        return false;
    }

    protected function compileCfgBlock(CfgBlock $block, array $params = [], ?CfgFunc $func = null): Block {
        if (null === $this->seen) {
            $this->seen = new SplObjectStorage;
        }
        if (!$this->seen->contains($block)) {
            $this->seen[$block] = $new = new Block($block);
            if (null !== $func) {
                $new->func = $func;
                $new->strictTypes = isset($func->strictTypes) ? (bool) $func->strictTypes : false;
                $this->applyReturnTypeFromFunc($new, $func);
            }
            $paramIdx = 0;
            foreach ($params as $param) {
                $new->addOpCode($this->compileParam($param, $new, $paramIdx++));
            }
            if (null !== $func && '__construct' === $func->name && null !== $func->class) {
                $this->compileCtorPromotionAssignments($new, $params);
            }
            $this->compileBlock($new);
        }
        /** @var mixed $out */
        $out = $this->seen[$block] ?? null;
        if (!$out instanceof Block) {
            if (null === $this->compileAbortDetail) {
                $this->compileAbortDetail = 'Compiler::compileCfgBlock: seen map returned non-Block';
            }
            // Best effort: keep going with a fresh Block so callers can surface a meaningful abort later.
            $out = new Block($block);
            $this->seen[$block] = $out;
        }

        return $out;
    }

    /**
     * CFG branch target within the current function: inherit parent locals ($this, params).
     */
    protected function compileCfgBranch(CfgBlock $block, Block $parent): Block {
        if (!$this->seen->contains($block)) {
            $this->seen[$block] = $new = new Block($block);
            $new->inheritScopeFrom($parent);
            $this->inheritFuncFromParent($new, $parent);
            $this->compileBlock($new);
        } else {
            $child = $this->seen[$block];
            $child->inheritScopeFrom($parent);
            $this->inheritFuncFromParent($child, $parent);
        }
        $child = $this->seen[$block];
        $child->parents[] = $parent;

        return $child;
    }

    /** Switch/if/loop targets need enclosing Func for JIT visibility (#210, #588). */
    private function inheritFuncFromParent(Block $child, Block $parent): void
    {
        if (null !== $parent->func) {
            $child->func = $parent->func;
            $child->strictTypes = $parent->strictTypes;
        }
    }

    protected function compileBlock(Block $block) {
        $this->compileOps($block->orig->children, $block);
    }

    protected function compileOps(array $ops, Block $block): void {
        // Hoist class-like definitions before functions so JIT/AOT see member
        // constants when compiling FUNCDEF bodies (issue #2215, MiniWebApp Router::CONST).
        foreach ($ops as $child) {
            switch (get_class($child)) {
                case Op\Stmt\Class_::class:
                    $block->addOpCode($this->compileClassLike($child, $block));
                    break;
                case Op\Stmt\Enum_::class:
                    $block->addOpCode($this->compileEnum($child, $block));
                    break;
                case Op\Stmt\Interface_::class:
                    $block->addOpCode($this->compileInterface($child, $block));
                    break;
                case Op\Stmt\Trait_::class:
                    $block->addOpCode($this->compileTrait($child, $block));
                    break;
            }
        }
        foreach ($ops as $child) {
            switch (get_class($child)) {
                case Op\Stmt\Function_::class:
                    $block->addOpCode($this->compileFunction($child, $block));
                    break;
                case Op\Terminal\Const_::class:
                    $block->addOpCode($this->compileGlobalConst($child, $block));
                    break;
            }
        }
        $opCount = count($ops);
        for ($i = 0; $i < $opCount; ++$i) {
            $child = $ops[$i];
            $this->debugWriteLastPhase('Compiler::compileOps op', $block, $child);
            switch (get_class($child)) {
                case Op\Stmt\Function_::class:
                case Op\Stmt\Class_::class:
                case Op\Stmt\Enum_::class:
                case Op\Terminal\Const_::class:
                case Op\Stmt\Interface_::class:
                case Op\Stmt\Trait_::class:
                    break;
                default:
                    if ($child instanceof Op\Expr\Isset_ && count($child->vars) > 1) {
                        $block = $this->compileIssetMulti($child, $block);
                    } elseif ($child instanceof Op\Expr\BinaryOp\Coalesce) {
                        $resultOverride = null;
                        if (
                            $i + 1 < $opCount
                            && $ops[$i + 1] instanceof Op\Expr\Assign
                            && $this->isCoalesceAssignTail($ops[$i + 1], $child)
                        ) {
                            /** @var Op\Expr\Assign $tailAssign */
                            $tailAssign = $ops[$i + 1];
                            $resultOverride = $tailAssign->var;
                        }
                        $block = $this->compileCoalesceForAssign($child, $block, $resultOverride);
                        if (null !== $resultOverride) {
                            ++$i;
                        }
                    } elseif ($child instanceof Op\Expr\NullsafePropertyFetch) {
                        $block = $this->compileNullsafePropertyFetch($child, $block);
                    } elseif ($child instanceof Op\Expr\NullsafeMethodCall) {
                        $block = $this->compileNullsafeMethodCall($child, $block);
                    } elseif (
                        $child instanceof Op\Expr\ArrayDimFetch
                        && $i + 1 < $opCount
                        && $ops[$i + 1] instanceof Op\Expr\BinaryOp\Coalesce
                        && $this->isArrayDimFetchOnlyCoalesceLeft($child, $ops[$i + 1])
                    ) {
                        /** @var Op\Expr\BinaryOp\Coalesce $coalesce */
                        $coalesce = $ops[$i + 1];
                        $resultOverride = null;
                        if (
                            $i + 2 < $opCount
                            && $ops[$i + 2] instanceof Op\Expr\Assign
                            && $this->isRedundantCoalesceTailAssign($ops[$i + 2], $child, $coalesce)
                        ) {
                            /** @var Op\Expr\Assign $tailAssign */
                            $tailAssign = $ops[$i + 2];
                            $resultOverride = $tailAssign->var;
                        }
                        $block = $this->compileCoalesceForAssign($coalesce, $block, $resultOverride);
                        ++$i;
                        if (null !== $resultOverride) {
                            ++$i;
                        }
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ArrayDimFetch
                        && $i + 1 < $opCount
                        && $this->isArrayDimFetchOnlyIssetVar($child, $ops[$i + 1])
                    ) {
                        // Lowered by compileIsset via isset(container, dim) — no eager fetch (#99, #273, #539).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ArrayDimFetch
                        && $i + 1 < $opCount
                        && $this->isArrayDimFetchOnlyUnsetVar($child, $ops[$i + 1])
                    ) {
                        // Lowered by compileTerminal Unset via TYPE_UNSET(container, dim) (#1224).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $i + 1 < $opCount
                        && $this->isPropertyFetchOnlyUnsetVar($child, $ops[$i + 1])
                    ) {
                        break;
                    } else {
                        if ($this->needsCfgSplitBeforeStringDimFetch($child, $block, $ops, $i)) {
                            $block = $this->splitCfgBlockAfterStringKeyedArray($block);
                        }
                        $echoBlock = $this->compileEchoWithEmbeddedCoalesce($child, $block, $ops, $i);
                        if (null !== $echoBlock) {
                            $block = $echoBlock;
                            break;
                        }
                        $this->compileOp($child, $block);
                    }
            }
        }
    }

    /**
     * String-key array writes and immediate dim fetch in one CFG block break AOT (#764, #783).
     * Keyed list destructuring (`["a" => $x] = …`) is excluded (#1234).
     *
     * @param Op[] $ops
     */
    private function needsCfgSplitBeforeStringDimFetch(Op $op, Block $block, array $ops, int $index): bool
    {
        if (!$op instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        if (!$op->dim instanceof Literal || !is_string($op->dim->value)) {
            return false;
        }
        if ($this->isKeyedListDestructDimFetch($ops, $index)) {
            return false;
        }
        foreach ($block->opCodes as $prev) {
            if (OpCode::TYPE_INIT_ARRAY === $prev->type && null !== $prev->arg3) {
                return true;
            }
            if (OpCode::TYPE_INCLUDE === $prev->type && null !== $prev->arg2) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg lowers `["key" => $v] = $array` to array literal + dim fetch + assign pairs (#1234).
     *
     * @param Op[] $ops
     */
    private function isKeyedListDestructDimFetch(array $ops, int $index): bool
    {
        if ($index + 1 >= count($ops) || !$ops[$index + 1] instanceof Op\Expr\Assign) {
            return false;
        }
        if (!$ops[$index] instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        /** @var Op\Expr\Assign $assign */
        $assign = $ops[$index + 1];

        return $assign->expr === $ops[$index]->result;
    }

    private function splitCfgBlockAfterStringKeyedArray(Block $block): Block
    {
        $cont = new Block($block->orig);
        $cont->inheritScopeFrom($block);
        $this->inheritFuncFromParent($cont, $block);
        $jumpToCont = new OpCode(OpCode::TYPE_JUMP);
        $jumpToCont->block1 = $cont;
        $block->addOpCode($jumpToCont);
        $cont->parents[] = $block;

        return $cont;
    }

    /**
     * php-cfg emits ArrayDimFetch as its own stmt before Coalesce; skip duplicate lowering.
     */
    private function isArrayDimFetchOnlyCoalesceLeft(
        Op\Expr\ArrayDimFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\BinaryOp\Coalesce) {
            return false;
        }
        $left = $next->left;
        while ($left instanceof Temporary) {
            if ($left === $fetch->result) {
                return true;
            }
            if (null === $left->original) {
                break;
            }
            $left = $left->original;
        }

        return $left === $fetch->result;
    }

    /**
     * php-cfg: ArrayDimFetch; Coalesce; Assign $dst = fetch-temp after ?? already stored in $dst.
     */
    private function isRedundantCoalesceTailAssign(
        Op\Expr\Assign $assign,
        Op\Expr\ArrayDimFetch $fetch,
        Op\Expr\BinaryOp\Coalesce $coalesce
    ): bool {
        return $this->isCoalesceAssignTail($assign, $coalesce);
    }

    /**
     * php-cfg: Coalesce; Assign $dst = coalesce-result for ??= (issue #1235).
     */
    private function isCoalesceAssignTail(
        Op\Expr\Assign $assign,
        Op\Expr\BinaryOp\Coalesce $coalesce
    ): bool {
        return $this->operandsChainEqual($assign->expr, $coalesce->result);
    }

    /**
     * Echo with embedded ?? / ??= must use compileCoalesce and continue on the merge block (#99, #1960, #1980).
     *
     * @param Op[] $ops
     */
    private function compileEchoWithEmbeddedCoalesce(Op $op, Block $block, array $ops, int $echoIndex): ?Block
    {
        if (!$op instanceof Op\Terminal || 'Terminal_Echo' !== $op->getType()) {
            return null;
        }
        $echoAfterAssign = $this->resolveEchoAfterCoalesceAssign($ops, $echoIndex, $op->expr);
        if (null !== $echoAfterAssign && $this->isStmtCoalesceLoweredBeforeEcho($ops, $echoIndex)) {
            $var = $this->compileOperand($echoAfterAssign, $block, true);
            $block->addOpCode(new OpCode(OpCode::TYPE_ECHO, $var));

            return $block;
        }
        $coalesces = $this->findEmbeddedCoalesces($op->expr);
        if ([] === $coalesces) {
            return null;
        }
        $echoOperand = $op->expr;
        foreach ($coalesces as $coalesce) {
            $resultOverride = $this->findEchoCoalesceAssignTarget($ops, $echoIndex, $coalesce);
            if (!$this->isCoalesceLoweredBeforeEcho($ops, $echoIndex, $coalesce)) {
                $block = $this->compileCoalesceForAssign($coalesce, $block, $resultOverride);
            }
            if (
                null === $this->unwrapConcatListExpr($echoOperand)
                && $this->operandsChainEqual($echoOperand, $coalesce->result)
            ) {
                $echoOperand = $resultOverride ?? $coalesce->result;
            }
        }
        $concat = $this->unwrapConcatListExpr($op->expr);
        if (null !== $concat) {
            $this->compileOp($concat, $block);
            $var = $this->compileOperand($concat->result, $block, true);
        } else {
            $var = $this->compileOperand($echoOperand, $block, true);
        }
        $block->addOpCode(new OpCode(OpCode::TYPE_ECHO, $var));

        return $block;
    }

    /**
     * php-cfg: Coalesce; Assign; Terminal_Echo(expr=coalesce.result) — echo the ??= lvalue (#1980).
     *
     * @param Op[] $ops
     */
    private function resolveEchoAfterCoalesceAssign(array $ops, int $echoIndex, Operand $echoExpr): ?Operand
    {
        if ($echoIndex < 2) {
            return null;
        }
        $assign = $ops[$echoIndex - 1];
        $coalesce = $ops[$echoIndex - 2];
        if (!$assign instanceof Op\Expr\Assign || !$coalesce instanceof Op\Expr\BinaryOp\Coalesce) {
            if (
                $echoIndex >= 3
                && $ops[$echoIndex - 1] instanceof Op\Expr\Assign
                && $ops[$echoIndex - 2] instanceof Op\Expr\ArrayDimFetch
                && $ops[$echoIndex - 3] instanceof Op\Expr\BinaryOp\Coalesce
            ) {
                /** @var Op\Expr\Assign $assign */
                $assign = $ops[$echoIndex - 1];
                /** @var Op\Expr\ArrayDimFetch $fetch */
                $fetch = $ops[$echoIndex - 2];
                /** @var Op\Expr\BinaryOp\Coalesce $coalesce */
                $coalesce = $ops[$echoIndex - 3];
                if (
                    $this->isRedundantCoalesceTailAssign($assign, $fetch, $coalesce)
                    && $this->operandsChainEqual($echoExpr, $coalesce->result)
                ) {
                    return $assign->var;
                }
            }

            return null;
        }
        if (
            $this->isCoalesceAssignTail($assign, $coalesce)
            && $this->operandsChainEqual($echoExpr, $coalesce->result)
        ) {
            return $assign->var;
        }

        return null;
    }

    /**
     * @param Op[] $ops
     */
    private function isStmtCoalesceLoweredBeforeEcho(array $ops, int $echoIndex): bool
    {
        if ($echoIndex >= 2 && $ops[$echoIndex - 2] instanceof Op\Expr\BinaryOp\Coalesce) {
            return true;
        }
        if ($echoIndex >= 3 && $ops[$echoIndex - 3] instanceof Op\Expr\BinaryOp\Coalesce) {
            return true;
        }

        return false;
    }

    /**
     * php-cfg: Coalesce; Assign; Terminal_Echo(expr=coalesce.result) for inline ??= (#1980).
     *
     * @param Op[] $ops
     */
    private function findEchoCoalesceAssignTarget(
        array $ops,
        int $echoIndex,
        Op\Expr\BinaryOp\Coalesce $coalesce
    ): ?Operand {
        if ($echoIndex > 0) {
            $prev = $ops[$echoIndex - 1];
            if ($prev instanceof Op\Expr\Assign && $this->isCoalesceAssignTail($prev, $coalesce)) {
                return $prev->var;
            }
        }
        if (
            $echoIndex > 2
            && $ops[$echoIndex - 2] instanceof Op\Expr\Assign
            && $ops[$echoIndex - 3] instanceof Op\Expr\ArrayDimFetch
        ) {
            /** @var Op\Expr\Assign $assign */
            $assign = $ops[$echoIndex - 2];
            /** @var Op\Expr\ArrayDimFetch $fetch */
            $fetch = $ops[$echoIndex - 3];
            if ($this->isRedundantCoalesceTailAssign($assign, $fetch, $coalesce)) {
                return $assign->var;
            }
        }

        return null;
    }

    /**
     * @param Op[] $ops
     */
    private function isCoalesceLoweredBeforeEcho(
        array $ops,
        int $echoIndex,
        Op\Expr\BinaryOp\Coalesce $coalesce
    ): bool {
        for ($j = $echoIndex - 1; $j >= 0; --$j) {
            if ($ops[$j] === $coalesce) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Op\Expr\BinaryOp\Coalesce>
     */
    private function findEmbeddedCoalesces(Operand $operand): array
    {
        $found = [];
        $coalesce = $this->unwrapCoalesceExpr($operand);
        if (null !== $coalesce) {
            $found[] = $coalesce;
        }
        $concat = $this->unwrapConcatListExpr($operand);
        if (null !== $concat) {
            foreach ($concat->list as $part) {
                foreach ($this->findEmbeddedCoalesces($part) as $nested) {
                    $found[] = $nested;
                }
            }
        }

        return $found;
    }

    private function compileCoalesceForAssign(
        Op\Expr\BinaryOp\Coalesce $coalesce,
        Block $block,
        ?Operand $resultOverride = null
    ): Block {
        if (null === $resultOverride) {
            $dimFetch = $this->findCoalesceArrayDimFetch($coalesce->left, $block);
            if (null !== $dimFetch && $this->operandsChainEqual($coalesce->result, $dimFetch->result)) {
                $resultOverride = $dimFetch->result;
            } elseif ($this->operandsChainEqual($coalesce->result, $coalesce->left)) {
                $resultOverride = $coalesce->left;
            }
        }

        return $this->compileCoalesce($coalesce, $block, $resultOverride);
    }

    /**
     * @return ?Op\Expr\ConcatList
     */
    private function unwrapConcatListExpr(Operand $operand): ?Op\Expr\ConcatList
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\ConcatList) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\ConcatList) {
            return $operand;
        }

        return null;
    }

    /**
     * @return ?Op\Expr\BinaryOp\Coalesce
     */
    private function unwrapCoalesceExpr(Operand $operand): ?Op\Expr\BinaryOp\Coalesce
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\BinaryOp\Coalesce) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\BinaryOp\Coalesce) {
            return $operand;
        }

        return null;
    }

    private function operandsChainEqual(Operand $a, Operand $b): bool
    {
        while ($a instanceof Temporary) {
            if ($a === $b) {
                return true;
            }
            if (null === $a->original) {
                break;
            }
            $a = $a->original;
        }
        while ($b instanceof Temporary) {
            if ($b === $a) {
                return true;
            }
            if (null === $b->original) {
                break;
            }
            $b = $b->original;
        }

        return $a === $b;
    }

    /**
     * php-cfg emits ArrayDimFetch as its own stmt before Isset_; skip duplicate lowering.
     */
    private function isArrayDimFetchOnlyIssetVar(
        Op\Expr\ArrayDimFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\Isset_) {
            return false;
        }
        foreach ($next->vars as $var) {
            $target = $var;
            while ($target instanceof Temporary) {
                if ($target === $fetch->result) {
                    return true;
                }
                if (null === $target->original) {
                    break;
                }
                $target = $target->original;
            }
            if ($target === $fetch->result) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg emits ArrayDimFetch as its own stmt before Terminal_Unset; skip duplicate lowering.
     */
    private function isArrayDimFetchOnlyUnsetVar(
        Op\Expr\ArrayDimFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Terminal\Unset_) {
            return false;
        }
        foreach ($next->exprs as $var) {
            if ($var === $fetch) {
                return true;
            }
            $target = $var;
            while ($target instanceof Temporary) {
                if ($target === $fetch->result) {
                    return true;
                }
                if (null === $target->original) {
                    break;
                }
                $target = $target->original;
            }
            if ($target === $fetch->result) {
                return true;
            }
        }

        return false;
    }

    private function isPropertyFetchOnlyUnsetVar(
        Op\Expr\PropertyFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Terminal\Unset_) {
            return false;
        }
        foreach ($next->exprs as $var) {
            if ($var === $fetch) {
                return true;
            }
            $target = $var;
            while ($target instanceof Temporary) {
                if ($target === $fetch->result) {
                    return true;
                }
                if (null === $target->original) {
                    break;
                }
                $target = $target->original;
            }
            if ($target === $fetch->result) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: int, 1: ?int}
     */
    protected function resolveUnsetTarget($expr, Block $block): array
    {
        if ($expr instanceof Op\Expr\ArrayDimFetch) {
            return $this->resolveIssetTargetFromArrayDimFetch($expr, $block);
        }
        if ($expr instanceof Op\Expr\PropertyFetch) {
            return [
                $this->compileOperand($expr->var, $block, true),
                $this->compileOperand($expr->name, $block, true),
            ];
        }
        if ($expr instanceof Op\Expr\StaticPropertyFetch) {
            $this->throwCompileLogic(
                'StaticPropertyFetch unset must be lowered via TYPE_STATIC_PROPERTY_UNSET (#2256)'
            );
        }
        if ($expr instanceof Operand) {
            $dimFetch = $this->findCoalesceArrayDimFetch($expr, $block);
            if (null !== $dimFetch) {
                return $this->resolveIssetTargetFromArrayDimFetch($dimFetch, $block);
            }
            foreach ($block->orig->children as $child) {
                if ($child instanceof Op\Expr\PropertyFetch && $child->result === $expr) {
                    return [
                        $this->compileOperand($child->var, $block, true),
                        $this->compileOperand($child->name, $block, true),
                    ];
                }
            }

            return $this->resolveIssetTarget($expr, $block);
        }

        $this->throwCompileLogic('Unsupported unset target: ' . (is_object($expr) ? $expr->getType() : gettype($expr)));
    }

    protected function compileInterface(Op\Stmt\Interface_ $iface, Block $block): OpCode
    {
        $return = new OpCode(
            OpCode::TYPE_DECLARE_INTERFACE,
            $this->compileOperand($iface->name, $block, true)
        );
        $return->classImplements = $this->interfaceNamesFromOperands($iface->extends);

        return $return;
    }

    protected function compileTrait(Op\Stmt\Trait_ $trait, Block $block): OpCode
    {
        $return = new OpCode(
            OpCode::TYPE_DECLARE_TRAIT,
            $this->compileOperand($trait->name, $block, true)
        );
        $return->attributeNames = AttributeNames::fromOp($trait);
        $return->block1 = $this->compileClassBody($trait->stmts, OpCode::TYPE_DECLARE_TRAIT);

        return $return;
    }

    protected function compileEnum(Op\Stmt\Enum_ $enum, Block $block): OpCode
    {
        $return = new OpCode(
            OpCode::TYPE_DECLARE_ENUM,
            $this->compileOperand($enum->name, $block, true)
        );
        $return->classImplements = $this->interfaceNamesFromOperands($enum->implements);
        $return->block1 = $this->compileEnumBody($enum->stmts);

        return $return;
    }

    protected function compileEnumBody(CfgBlock $block): Block
    {
        $result = new Block($block);
        foreach ($block->children as $child) {
            if ($child instanceof Op\Terminal\Const_) {
                $this->compileOps($child->valueBlock->children, $result);
                $result->addOpCode(new OpCode(
                    OpCode::TYPE_DECLARE_CLASS_CONST,
                    $this->compileOperand($child->name, $result, true),
                    $this->compileOperand($child->value, $result, true)
                ));
                continue;
            }
            if ($child instanceof Op\Stmt\ClassMethod) {
                $this->compileClassMethodDeclaration($child, $result);

                continue;
            }
            $this->throwCompileLogic('Unsupported enum body element: '.get_class($child));
        }

        return $result;
    }

    protected function compileClassMethodDeclaration(Op\Stmt\ClassMethod $child, Block $result): void
    {
        if ('__construct' === $child->func->name) {
            foreach ($child->func->params as $param) {
                if ($this->isPromotedParam($param)) {
                    $this->compilePromotedPropertyDeclaration($param, $result);
                }
            }
        }
        $methodName = new Operand\Literal($child->func->name);
        $methodName->type = Type::string();
        $visVar = new Variable(Variable::TYPE_INTEGER);
        $visVar->int(MethodVisibility::mask($child->func->flags));
        $visOperand = new Operand\Temporary;
        $visOperand->type = Type::int();
        $visIdx = $result->registerConstant($visOperand, $visVar);
        $declare = new OpCode(
            OpCode::TYPE_DECLARE_METHOD,
            $this->compileOperand($methodName, $result, true),
            null,
            $visIdx
        );
        if (null !== $child->func->cfg) {
            $methodBlock = $this->compileCfgBlock($child->func->cfg, $child->func->params, $child->func);
            $declare->block1 = $methodBlock;
        }
        $declare->attributeNames = AttributeNames::fromOp($child);
        $result->addOpCode($declare);
    }

    protected function compileClassLike(Op\Stmt\ClassLike $class, Block $block): OpCode {
        $type = 0;
        if ($class instanceof Op\Stmt\Class_) {
            $type = OpCode::TYPE_DECLARE_CLASS;
        } else {
            $this->throwCompileLogic('Unsupported class type: ' . get_class($class));
        }
        $parentSlot = null;
        if ($class instanceof Op\Stmt\Class_ && null !== $class->extends) {
            $parentSlot = $this->compileOperand($class->extends, $block, true);
        }
        $readonlyVar = new Variable(Variable::TYPE_INTEGER);
        $readonlyVar->int(
            VM\ClassReadonly::fromClassFlags($class->flags) ? 1 : 0
        );
        $readonlyOperand = new Operand\Temporary;
        $readonlyOperand->type = Type::int();
        $readonlySlot = $block->registerConstant($readonlyOperand, $readonlyVar);
        $return = new OpCode(
            $type,
            $this->compileOperand($class->name, $block, true),
            $parentSlot,
            $readonlySlot
        );
        $return->classImplements = $this->interfaceNamesFromOperands($class->implements);
        $return->attributeNames = AttributeNames::fromOp($class);
        $return->block1 = $this->compileClassBody($class->stmts, $type);
        return $return;
    }

    /**
     * @param Operand[] $operands
     *
     * @return list<string>
     */
    protected function interfaceNamesFromOperands(array $operands): array
    {
        $names = [];
        foreach ($operands as $operand) {
            $name = $this->staticNameFromOperand($operand);
            if (null === $name) {
                $this->throwCompileError('Interface name must be a compile-time class reference');
            }
            $names[] = strtolower(ltrim($name, '\\'));
        }

        return $names;
    }

    protected function staticNameFromOperand(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return $this->staticNameFromOperand($op->name);
        }

        return null;
    }

    protected function rejectDeferredParentAccess(?string $className, string $construct): void
    {
        if (null === $className || 'parent' !== strtolower($className)) {
            return;
        }
        $this->throwCompileError("{$construct} is not supported (issue #1858)");
    }

    protected function literalScopeClassName(Operand $class): ?string
    {
        if ($class instanceof Operand\Literal && is_string($class->value)) {
            return $class->value;
        }
        if ($class instanceof Operand\Variable) {
            return $this->literalScopeClassName($class->name);
        }
        if (null !== $class->original) {
            if ($class->original instanceof \PhpParser\Node\Name) {
                return $class->original->toString();
            }
            if ($class->original instanceof Operand) {
                return $this->literalScopeClassName($class->original);
            }
        }

        return $this->staticNameFromOperand($class);
    }

    /**
     * @return list<string>
     */
    protected function intersectionNamesFromCfgType(Op\Type\Intersection $type): array
    {
        $names = [];
        foreach ($type->types as $member) {
            $name = $this->staticNameFromCfgType($member);
            if (null === $name) {
                $this->throwCompileError('Intersection type members must be interface names');
            }
            $names[] = strtolower(ltrim($name, '\\'));
        }

        return $names;
    }

    protected function staticNameFromCfgType(?Op\Type $type): ?string
    {
        if (null === $type) {
            return null;
        }
        if ($type instanceof Op\Type\Literal) {
            return $type->name;
        }
        if ($type instanceof Op\Type\Reference) {
            return $this->staticNameFromOperand($type->declaration);
        }

        return null;
    }

    protected function applyParamDeclaredType(Op\Expr\Param $param, Block $block, int $slot): void
    {
        $declared = $param->declaredType;
        if ($declared instanceof Op\Type\Intersection) {
            $block->paramTypeConstraints[$slot] = Variable::TYPE_OBJECT;
            $block->paramIntersectionConstraints[$slot] = $this->intersectionNamesFromCfgType($declared);

            return;
        }
        if ($declared instanceof Op\Type\Literal) {
            $declName = strtolower($declared->name);
            if ('mixed' !== $declName) {
                $rawType = Type::fromDecl($declared->name);
                $mapped = Variable::mapFromType($rawType);
                if ($mapped !== Variable::TYPE_UNDEFINED) {
                    $block->paramTypeConstraints[$slot] = $mapped;
                }
            }
        }
    }

    protected function compileClassBody(CfgBlock $block, int $type): Block {
        $result = new Block($block);
        foreach ($block->children as $child) {
            switch (get_class($child)) {
                case Op\Stmt\Property::class:
                    if (OpCode::TYPE_DECLARE_CLASS !== $type) {
                        $this->throwCompileLogic('Properties are only supported on classes for now');
                    }
                    if (!is_null($child->defaultBlock)) {
                        $this->compileOps($child->defaultBlock->children, $result);
                    }
                    $declared = $child->declaredType instanceof Op\Type\Literal
                        ? Type::fromDecl($child->declaredType->name)
                        : ($child->type ?? Type::mixed());
                    $declareType = $child->static
                        ? OpCode::TYPE_DECLARE_STATIC_PROPERTY
                        : OpCode::TYPE_DECLARE_PROPERTY;
                    $result->addOpCode(new OpCode(
                        $declareType,
                        $this->compileOperand($child->name, $result, true),
                        is_null($child->defaultVar) ? null : $this->compileOperand($child->defaultVar, $result, true),
                        $this->compileTypeConstrainedVariable($result, $declared)
                    ));
                    break;
                case Op\Stmt\ClassMethod::class:
                    $this->compileClassMethodDeclaration($child, $result);
                    break;
                case Op\Terminal\Const_::class:
                    if (OpCode::TYPE_DECLARE_CLASS !== $type) {
                        $this->throwCompileLogic('Class constants are only supported on classes for now');
                    }
                    $this->compileOps($child->valueBlock->children, $result);
                    $result->addOpCode(new OpCode(
                        OpCode::TYPE_DECLARE_CLASS_CONST,
                        $this->compileOperand($child->name, $result, true),
                        $this->compileOperand($child->value, $result, true)
                    ));
                    break;
                case Op\Stmt\TraitUse::class:
                    if (OpCode::TYPE_DECLARE_CLASS !== $type) {
                        $this->throwCompileLogic('Trait use is only supported on classes for now');
                    }
                    if ([] !== $child->adaptations) {
                        $this->throwCompileLogic('TraitUseAdaptation is not supported yet');
                    }
                    foreach ($child->traits as $traitOperand) {
                        $result->addOpCode(new OpCode(
                            OpCode::TYPE_USE_TRAIT,
                            $this->compileOperand($traitOperand, $result, true)
                        ));
                    }
                    break;
                default:
                    $this->throwCompileLogic('Unsupported class body element: ' . get_class($child));
            }
        }
        return $result;
    }

    protected function isPromotedParam(Op\Expr\Param $param): bool
    {
        return property_exists($param, 'promotionFlags') && 0 !== $param->promotionFlags;
    }

    protected function compilePromotedPropertyDeclaration(Op\Expr\Param $param, Block $result): void
    {
        if (!is_null($param->defaultBlock)) {
            $this->compileOps($param->defaultBlock->children, $result);
        }
        $declared = $param->declaredType instanceof Op\Type\Literal
            ? Type::fromDecl($param->declaredType->name)
            : Type::mixed();
        $propName = new Operand\Literal($param->name->value);
        $propName->type = Type::string();
        $result->addOpCode(new OpCode(
            OpCode::TYPE_DECLARE_PROPERTY,
            $this->compileOperand($propName, $result, true),
            is_null($param->defaultVar) ? null : $this->compileOperand($param->defaultVar, $result, true),
            $this->compileTypeConstrainedVariable($result, $declared)
        ));
    }

    /**
     * @param list<Op\Expr\Param> $params
     */
    protected function compileCtorPromotionAssignments(Block $block, array $params): void
    {
        $thisVar = new Operand\Variable(new Operand\Literal('this'));
        $thisSlot = $block->getVarSlot($thisVar, true);

        foreach ($params as $param) {
            if (!$this->isPromotedParam($param)) {
                continue;
            }
            if (!($param->name instanceof Operand\Literal) || !is_string($param->name->value)) {
                $this->throwCompileLogic('Promoted constructor parameter must have a simple name');
            }
            $propName = new Operand\Literal($param->name->value);
            $propName->type = Type::string();
            $propSlot = $this->compileOperand($propName, $block, true);
            $fetchTmp = new Temporary();
            $fetchSlot = $block->getVarSlot($fetchTmp, false);
            $paramSlot = $this->compileOperand($param->result, $block, true);
            $block->addOpCode(new OpCode(
                OpCode::TYPE_PROPERTY_FETCH,
                $fetchSlot,
                $thisSlot,
                $propSlot
            ));
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ASSIGN,
                $fetchSlot,
                $fetchSlot,
                $paramSlot
            ));
        }
    }

    protected function compileTypeConstrainedVariable(Block $block, Type $type): int {
        $var = new Variable(Variable::TYPE_UNDEFINED);
        $operand = new Operand\Temporary;
        $operand->type = $type;
        $return = $block->registerConstant($operand, $var);
        $mappedType = Variable::mapFromType($type);
        if ($mappedType === Variable::TYPE_UNDEFINED) {
            // Mixed
            return $return;
        } elseif ($mappedType === Variable::TYPE_OBJECT) {
            $var->classConstraint = $type->userType;
        }
        $var->typeConstraint = $mappedType;
        return $return;
    }


    protected function compileParam(Op\Expr\Param $param, Block $block, int $paramIdx): OpCode {
        if ($param->byRef) {
            $block->paramByRef[$paramIdx] = true;
        }
        if ($param->variadic) {
            assert(null === $param->defaultVar);
            if (null !== $block->variadicParamIndex) {
                $this->throwCompileLogic('Only one variadic parameter is allowed per function');
            }
            $block->variadicParamIndex = $paramIdx;
        }
        $defaultConst = null;
        if (null !== $param->defaultVar) {
            $defaultConst = $this->compileOperand($param->defaultVar, $block, true);
        }
        $slot = $this->compileOperand($param->result, $block, false);
        if ($param->name instanceof Operand\Literal && is_string($param->name->value)) {
            $block->paramNames[$paramIdx] = $param->name->value;
        }
        $this->applyParamDeclaredType($param, $block, $slot);

        return new OpCode(
            OpCode::TYPE_ARG_RECV,
            $slot,
            $paramIdx,
            $defaultConst
        );
    }

    protected function compileFunction(Op\Stmt\Function_ $function, Block $block): OpCode {
        $funcBlock = $this->compileCfgBlock($function->func->cfg, $function->func->params, $function->func);
        $operand = new Operand\Literal($function->func->name);
        $operand->type = Type::string();
        $return = new OpCode(
            OpCode::TYPE_FUNCDEF,
            $this->compileOperand($operand, $block, true)
        );
        $return->block1 = $funcBlock;
        return $return;
    }

    protected function compileOp(Op $op, Block $block) {
        if ($op instanceof Op\Expr\ConcatList) {
            $total = count($op->list);
            $return = $this->compileOperand($op->result, $block, false);
            if (0 === $total) {
                $empty = new Operand\Literal('');
                $empty->type = Type::string();
                $emptySlot = $this->compileOperand($empty, $block, true);
                $block->addOpCode(new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $return,
                    $return,
                    $emptySlot
                ));
            } elseif (1 === $total) {
                $part = $this->compileOperand($op->list[0], $block, true);
                $block->addOpCode(new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $return,
                    $return,
                    $part
                ));
            } else {
                $pointer = 2;
                $block->addOpCode(new OpCode(
                    OpCode::TYPE_CONCAT,
                    $return,
                    $this->compileOperand($op->list[0], $block, true),
                    $this->compileOperand($op->list[1], $block, true)
                ));
                while ($pointer < $total) {
                    $right = $this->compileOperand($op->list[$pointer++], $block, true);
                    $block->addOpCode(new OpCode(
                        OpCode::TYPE_CONCAT,
                        $return,
                        $return,
                        $right
                    ));
                }
            }
        } elseif ($op instanceof Op\Expr) {
            $block->addOpCode(...$this->compileExpr($op, $block));
        } elseif ($op instanceof Op\Stmt) {
            $this->compileStmt($op, $block);
        } elseif ($op instanceof Op\Terminal) {
            $terminalOps = $this->compileTerminal($op, $block);
            foreach ($terminalOps as $terminalOp) {
                $block->addOpCode($terminalOp);
            }
        } else {
            $this->throwCompileLogic("Unknown Op Type: " . opcode_type_name($op->type));
        }
    }

    protected function compileStmt(Op\Stmt $stmt, Block $block) {
        if ($stmt instanceof Op\Stmt\Jump) {
            $target = $this->compileCfgBranch($stmt->target, $block);
            if (!$this->isRedundantTryEntryJump($block, $target)) {
                $op = new OpCode(OpCode::TYPE_JUMP);
                $op->block1 = $target;
                $block->addOpCode($op);
            }
        } elseif ($stmt instanceof Op\Stmt\JumpIf) {
            $op = new OpCode(OpCode::TYPE_JUMPIF, $this->compileOperand($stmt->cond, $block, true));
            $op->block1 = $this->compileCfgBranch($stmt->if, $block);
            $op->block2 = $this->compileCfgBranch($stmt->else, $block);
            $block->addOpCode($op);
        } elseif ($stmt instanceof Op\Stmt\TryCatch) {
            $merge = $this->compileCfgBranch($stmt->end, $block);
            $try = $this->compileCfgBranch($stmt->try, $block);
            $tryOp = new OpCode(OpCode::TYPE_TRY);
            $tryOp->block1 = $try;
            $tryOp->block2 = $merge;
            $block->addOpCode($tryOp);
            foreach ($stmt->catches as $i => $catchBlock) {
                $compiledCatch = $this->compileCfgBranch($catchBlock, $block);
                $catchOp = new OpCode(OpCode::TYPE_CATCH);
                $catchOp->block1 = $compiledCatch;
                $catchOp->block2 = $merge;
                $catchOp->catchTypes = $this->encodeCatchTypeList($stmt->catchTypes[$i] ?? []);
                $catchVar = $stmt->catchVars[$i] ?? null;
                $catchOp->arg3 = null !== $catchVar
                    ? $compiledCatch->slotForOperand($catchVar)
                    : null;
                $block->addOpCode($catchOp);
            }
            if (null !== $stmt->finally) {
                $compiledFinally = $this->compileCfgBranch($stmt->finally, $block);
                $finallyOp = new OpCode(OpCode::TYPE_FINALLY);
                $finallyOp->block1 = $compiledFinally;
                $finallyOp->block2 = $merge;
                $block->addOpCode($finallyOp);
                $this->rewriteTryMergeJumpsToFinally($try, $merge, $compiledFinally);
            }
        } elseif ($stmt instanceof Op\Stmt\Switch_) {
            $this->compileSwitchAsJumpIfChain($stmt, $block);
        } else {
            $this->throwCompileLogic("Unknown Stmt Type: " . $stmt->getType());
        }
    }

    /**
     * Lower CFG switch to JUMPIF/EQUAL chain (JIT-safe; TYPE_CASE branchIf needs bool #96).
     */
    protected function compileSwitchAsJumpIfChain(Op\Stmt\Switch_ $switch, Block $block): void
    {
        if (!isset($switch->cond)) {
            $this->throwCompileLogic('Switch missing condition operand');
        }
        $condSlot = $this->requireOperandSlot(
            $this->compileOperand($switch->cond, $block, true),
            'switch condition'
        );
        $caseCount = count($switch->cases);
        if (0 === $caseCount) {
            $defaultOp = new OpCode(OpCode::TYPE_JUMP);
            $defaultOp->block1 = $this->compileCfgBranch($switch->default, $block);
            $block->addOpCode($defaultOp);

            return;
        }

        $current = $block;
        for ($i = 0; $i < $caseCount; ++$i) {
            $eqSlot = $this->requireOperandSlot(
                $this->compileBoolTemporary($current),
                'switch equality temporary'
            );
            $caseSlot = $this->requireOperandSlot(
                $this->compileOperand($switch->cases[$i], $current, true),
                'switch case #'.$i
            );
            $current->addOpCode(new OpCode(
                OpCode::TYPE_EQUAL,
                $eqSlot,
                $condSlot,
                $caseSlot
            ));

            $caseTarget = $this->compileCfgBranch($switch->targets[$i], $block);
            $isLast = $i === $caseCount - 1;
            if ($isLast) {
                $elseTarget = $this->compileCfgBranch($switch->default, $block);
            } else {
                $elseTarget = new Block($block->orig);
                $elseTarget->syntheticCfgBranch = true;
                $elseTarget->inheritUndefinedLocals = true;
                $elseTarget->inheritScopeFrom($current);
                $this->inheritFuncFromParent($elseTarget, $block);
            }

            $jump = new OpCode(OpCode::TYPE_JUMPIF, $eqSlot);
            $jump->block1 = $caseTarget;
            $jump->block2 = $elseTarget;
            $current->addOpCode($jump);
            $caseTarget->parents[] = $current;
            $elseTarget->parents[] = $current;
            if (!$isLast) {
                $current = $elseTarget;
            }
        }
    }

    protected function getOpCodeTypeFromBinaryOp(Op\Expr\BinaryOp $expr): int {
        if ($expr instanceof Op\Expr\BinaryOp\Concat) {
            return OpCode::TYPE_CONCAT;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Plus) {
            return OpCode::TYPE_PLUS;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Smaller) {
            return OpCode::TYPE_SMALLER;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Greater) {
            return OpCode::TYPE_GREATER;
        } elseif ($expr instanceof Op\Expr\BinaryOp\SmallerOrEqual) {
            return OpCode::TYPE_SMALLER_OR_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\GreaterOrEqual) {
            return OpCode::TYPE_GREATER_OR_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Equal) {
            return OpCode::TYPE_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\NotEqual) {
            return OpCode::TYPE_NOT_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Identical) {
            return OpCode::TYPE_IDENTICAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\NotIdentical) {
            return OpCode::TYPE_NOT_IDENTICAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Spaceship) {
            return OpCode::TYPE_SPACESHIP;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Minus) {
            return OpCode::TYPE_MINUS;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Mul) {
            return OpCode::TYPE_MUL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Div) {
            return OpCode::TYPE_DIV;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Mod) {
            return OpCode::TYPE_MODULO;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Pow) {
            return OpCode::TYPE_POW;
        } elseif ($expr instanceof Op\Expr\BinaryOp\BitwiseAnd) {
            return OpCode::TYPE_BITWISE_AND;
        } elseif ($expr instanceof Op\Expr\BinaryOp\BitwiseOr) {
            return OpCode::TYPE_BITWISE_OR;
        } elseif ($expr instanceof Op\Expr\BinaryOp\BitwiseXor) {
            return OpCode::TYPE_BITWISE_XOR;
        } elseif ($expr instanceof Op\Expr\BinaryOp\ShiftLeft) {
            return OpCode::TYPE_SHIFT_LEFT;
        } elseif ($expr instanceof Op\Expr\BinaryOp\ShiftRight) {
            return OpCode::TYPE_SHIFT_RIGHT;
        }
        $this->throwCompileLogic("Unknown BinaryOp Type: " . $expr->getType());
    }

    protected function getOpCodeTypeFromCastOp(Op\Expr\Cast $expr): int {
        if ($expr instanceof Op\Expr\Cast\Array_) {
            return OpCode::TYPE_CAST_ARRAY;
        } elseif ($expr instanceof Op\Expr\Cast\Bool_) {
            return OpCode::TYPE_CAST_BOOL;
        } elseif ($expr instanceof Op\Expr\Cast\Double) {
            return OpCode::TYPE_CAST_FLOAT;
        } elseif ($expr instanceof Op\Expr\Cast\Int_) {
            return OpCode::TYPE_CAST_INT;
        } elseif ($expr instanceof Op\Expr\Cast\Object_) {
            return OpCode::TYPE_CAST_OBJECT;
        } elseif ($expr instanceof Op\Expr\Cast\String_) {
            return OpCode::TYPE_CAST_STRING;
        } elseif ($expr instanceof Op\Expr\Cast\Unset_) {
            return OpCode::TYPE_CAST_UNSET;
        }
        $this->throwCompileLogic("Unknown CastOp Type: " . $expr->getType());
    }

    protected function getOpCodeTypeFromUnaryOp(Op\Expr $expr): int {
        if ($expr instanceof Op\Expr\UnaryMinus) {
            return OpCode::TYPE_UNARY_MINUS;
        } elseif ($expr instanceof Op\Expr\UnaryPlus) {
            return OpCode::TYPE_UNARY_PLUS;
        } elseif ($expr instanceof Op\Expr\BitwiseNot) {
            return OpCode::TYPE_BITWISE_NOT;
        } elseif ($expr instanceof Op\Expr\BooleanNot) {
            return OpCode::TYPE_BOOLEAN_NOT;
        } elseif ($expr instanceof Op\Expr\Clone_) {
            return OpCode::TYPE_CLONE;
        } elseif ($expr instanceof Op\Expr\Empty_) {
            return OpCode::TYPE_EMPTY;
        } elseif ($expr instanceof Op\Expr\Eval_) {
            return OpCode::TYPE_EVAL;
        } elseif ($expr instanceof Op\Expr\Exit_) {
            return OpCode::TYPE_EXIT;
        } elseif ($expr instanceof Op\Expr\Print_) {
            return OpCode::TYPE_PRINT;
        }
        $this->throwCompileLogic("Unknown UnaryOp Type: " . $expr->getType());
    }

    protected function compileExpr(Op\Expr $expr, Block $block): array {
        if ($expr instanceof Op\Expr\BinaryOp) {
            return [new OpCode(
                $this->getOpCodeTypeFromBinaryOp($expr),
                $this->compileOperand($expr->result, $block, false),
                null !== $expr->left ? $this->compileOperand($expr->left, $block, true) : null,
                null !== $expr->right ? $this->compileOperand($expr->right, $block, true) : null,
            )];
        } elseif ($expr instanceof Op\Expr\Cast) {
            return [new OpCode(
                $this->getOpCodeTypeFromCastOp($expr),
                $this->compileOperand($expr->result, $block, false),
                $this->compileOperand($expr->expr, $block, true),
            )];
        }
        switch (get_class($expr)) {
            case Op\Expr\ArrowFunction::class:
                return $this->compileAnonymousFunctionExpr($expr, $block);
            case Op\Expr\Closure::class:
                return $this->compileAnonymousFunctionExpr($expr, $block);
            case Op\Expr\Assertion::class:
                if ($expr->result instanceof Operand\Literal) {
                    //short circuit
                    return [];
                } elseif ($expr->expr === $expr->result) {
                    return [];
                }
                return [new OpCode(
                    OpCode::TYPE_TYPE_ASSERT,
                    $this->compileOperand($expr->result, $block, false),   
                    $this->compileOperand($expr->expr, $block, true) 
                )];
            case Op\Expr\Assign::class:
                $staticPropertyFetch = $this->unwrapStaticPropertyFetch($expr->var);
                if (null !== $staticPropertyFetch) {
                    $fetchSlot = $this->compileOperand($staticPropertyFetch->result, $block, false);
                    $rhsSlot = $this->compileOperand($expr->expr, $block, true);
                    $ops = [
                        new OpCode(
                            OpCode::TYPE_STATIC_PROPERTY_FETCH,
                            $fetchSlot,
                            $this->compileOperand($staticPropertyFetch->class, $block, true),
                            $this->compileOperand($staticPropertyFetch->name, $block, true)
                        ),
                        new OpCode(
                            OpCode::TYPE_ASSIGN,
                            $fetchSlot,
                            $fetchSlot,
                            $rhsSlot
                        ),
                    ];
                    if ([] !== $expr->result->usages) {
                        $ops[] = new OpCode(
                            OpCode::TYPE_ASSIGN,
                            $this->compileOperand($expr->result, $block, false),
                            $fetchSlot,
                            $rhsSlot
                        );
                    }

                    return $ops;
                }
                $propertyFetch = $this->unwrapPropertyFetch($expr->var);
                if (null !== $propertyFetch) {
                    $fetchSlot = $this->compileOperand($propertyFetch->result, $block, false);
                    $rhsSlot = $this->compileOperand($expr->expr, $block, true);
                    $ops = [
                        new OpCode(
                            OpCode::TYPE_PROPERTY_FETCH,
                            $fetchSlot,
                            $this->compileOperand($propertyFetch->var, $block, true),
                            $this->compileOperand($propertyFetch->name, $block, true)
                        ),
                        new OpCode(
                            OpCode::TYPE_ASSIGN,
                            $fetchSlot,
                            $fetchSlot,
                            $rhsSlot
                        ),
                    ];
                    if ([] !== $expr->result->usages) {
                        $ops[] = new OpCode(
                            OpCode::TYPE_ASSIGN,
                            $this->compileOperand($expr->result, $block, false),
                            $fetchSlot,
                            $rhsSlot
                        );
                    }

                    return $ops;
                }

                return [new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, false),
                    $this->compileOperand($expr->expr, $block, true)
                )];
            case Op\Expr\Exit_::class:
                $exitExpr = null !== $expr->expr
                    ? $this->compileOperand($expr->expr, $block, true)
                    : null;

                return [new OpCode(
                    OpCode::TYPE_EXIT,
                    $this->compileOperand($expr->result, $block, false),
                    $exitExpr
                )];
            case Op\Expr\UnaryMinus::class:
            case Op\Expr\UnaryPlus::class:
            case Op\Expr\BitwiseNot::class:
            case Op\Expr\BooleanNot::class:
            case Op\Expr\Clone_::class:
            case Op\Expr\Empty_::class:
            case Op\Expr\Eval_::class:
                return [new OpCode(
                    $this->getOpCodeTypeFromUnaryOp($expr),
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->expr, $block, true)
                )];
            case Op\Expr\Print_::class:
                return [new OpCode(
                    $this->getOpCodeTypeFromUnaryOp($expr),
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->expr, $block, true)
                )];
            case Op\Expr\ArrayDimFetch::class:
                $dimSlot = null !== $expr->dim
                    ? $this->compileOperand($expr->dim, $block, true)
                    : null;
                $fetchType = $this->isArrayDimFetchForWrite($expr, $block)
                    ? OpCode::TYPE_ARRAY_DIM_FETCH_WRITE
                    : OpCode::TYPE_ARRAY_DIM_FETCH;

                return [new OpCode(
                    $fetchType,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, true),
                    $dimSlot
                )];
            case Op\Expr\ConstFetch::class:
                $nsName = null;
                if (!is_null($expr->nsName)) {
                    $nsName = $this->compileOperand($expr->nsName, $block, true);
                }
                return [new OpCode(
                    OpCode::TYPE_CONST_FETCH,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->name, $block, true),
                    $nsName
                )];
            case Op\Expr\ClassConstFetch::class:
                return $this->compileClassConstFetch($expr, $block);
            case Op\Expr\StaticPropertyFetch::class:
                $this->rejectDeferredParentAccess(
                    $this->literalScopeClassName($expr->class),
                    'parent::$property'
                );

                return [new OpCode(
                    OpCode::TYPE_STATIC_PROPERTY_FETCH,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->class, $block, true),
                    $this->compileOperand($expr->name, $block, true)
                )];
            case Op\Expr\FirstClassCallable::class:
                return $this->compileFirstClassCallable($expr, $block);
            case Op\Expr\FuncCall::class:
                if ($this->operandIsInvokableReceiver($expr->name, $block)) {
                    return $this->compileMethodCallOpcodes(
                        $this->compileOperand($expr->name, $block, true),
                        $this->compileOperand(new Operand\Literal('__invoke'), $block, true),
                        $expr->args,
                        $expr->result,
                        $block
                    );
                }

                return $this->compileFuncCall(
                    $this->compileOperand($expr->name, $block, true),
                    $expr->args,
                    $expr->result,
                    $block
                );
            case Op\Expr\NsFuncCall::class:
                if ($this->operandIsInvokableReceiver($expr->nsName, $block)) {
                    return $this->compileMethodCallOpcodes(
                        $this->compileOperand($expr->nsName, $block, true),
                        $this->compileOperand(new Operand\Literal('__invoke'), $block, true),
                        $expr->args,
                        $expr->result,
                        $block
                    );
                }

                return $this->compileFuncCall(
                    $this->compileOperand($expr->nsName, $block, true),
                    $expr->args,
                    $expr->result,
                    $block
                );
            case Op\Expr\StaticCall::class:
                $return = [
                    new OpCode(
                        OpCode::TYPE_STATICCALL_INIT,
                        $this->compileOperand($expr->class, $block, true),
                        $this->compileOperand($expr->name, $block, true)
                    )
                ];
                foreach ($this->compileCallArgSends($expr->args, $block) as $send) {
                    $return[] = $send;
                }
                if ($this->callNeedsReturnSlot($expr->result, $block)) {
                    $return[] = new OpCode(
                        OpCode::TYPE_FUNCCALL_EXEC_RETURN,
                        $this->compileOperand($expr->result, $block, false)
                    );
                } else {
                    $return[] = new OpCode(
                        OpCode::TYPE_FUNCCALL_EXEC_NORETURN,
                    );
                }
                return $return;
            case Op\Expr\New_::class:
                $return = [
                    new OpCode(
                        OpCode::TYPE_NEW,
                        $this->compileOperand($expr->result, $block, false),
                        $this->compileOperand($expr->class, $block, true),
                    )
                ];
                foreach ($this->compileCallArgSends($expr->args, $block) as $send) {
                    $return[] = $send;
                }
                $return[] = new OpCode(
                    OpCode::TYPE_FUNCCALL_EXEC_NORETURN
                );
                return $return;
            case Op\Expr\MethodCall::class:
                return $this->compileMethodCallOpcodes(
                    $this->compileOperand($expr->var, $block, true),
                    $this->compileOperand($expr->name, $block, true),
                    $expr->args,
                    $expr->result,
                    $block
                );
            case Op\Expr\PropertyFetch::class:
                return [new OpCode(
                    OpCode::TYPE_PROPERTY_FETCH,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, true),
                    $this->compileOperand($expr->name, $block, true)
                )];
            case Op\Expr\Array_::class:
                return $this->compileArrayLiteral($expr, $block);
            case Op\Expr\MagicScriptConst::class:
                $line = Op\Expr\MagicScriptConst::KIND_LINE === $expr->kind
                    ? max(1, $expr->getLine())
                    : null;

                return [new OpCode(
                    OpCode::TYPE_SCRIPT_MAGIC,
                    $this->compileOperand($expr->result, $block, false),
                    $line,
                    $expr->kind,
                )];
            case Op\Expr\Include_::class:
                return [$this->compileIncludeOp($expr, $block)];
            case Op\Expr\Isset_::class:
                return $this->compileIsset($expr, $block);
            case Op\Iterator\Valid::class:
                return [new OpCode(
                    OpCode::TYPE_ITER_VALID,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, true)
                )];
            case Op\Iterator\Key::class:
                return [new OpCode(
                    OpCode::TYPE_ITER_KEY,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, true)
                )];
            case Op\Iterator\Value::class:
                return [new OpCode(
                    OpCode::TYPE_ITER_VALUE,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, true),
                    $expr->byRef ? 1 : 0
                )];
            case Op\Expr\InstanceOf_::class:
                return $this->compileInstanceOf($expr, $block);
            case Op\Expr\AssignRef::class:
                $ops = [new OpCode(
                    OpCode::TYPE_ASSIGN_REF,
                    $this->compileOperand($expr->var, $block, false),
                    $this->compileOperand($expr->expr, $block, true)
                )];
                if ([] !== $expr->result->usages) {
                    $ops[] = new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $this->compileOperand($expr->result, $block, false),
                        $this->compileOperand($expr->var, $block, false),
                        $this->compileOperand($expr->expr, $block, true)
                    );
                }
                return $ops;
            case Op\Expr\Yield_::class:
                $this->markFunctionGenerator($block);

                return [new OpCode(
                    OpCode::TYPE_YIELD,
                    null,
                    null !== $expr->value
                        ? $this->compileOperand($expr->value, $block, true)
                        : (null !== $expr->key
                            ? $this->compileOperand($expr->key, $block, true)
                            : null),
                    null !== $expr->value && null !== $expr->key
                        ? $this->compileOperand($expr->key, $block, true)
                        : null,
                )];
            case Op\Expr\YieldFrom::class:
                $this->markFunctionGenerator($block);
                return [new OpCode(
                    OpCode::TYPE_YIELD_FROM,
                    null,
                    $this->compileOperand($expr->expr, $block, true),
                )];
        }
        $this->throwCompileLogic("Unsupported expression: " . $expr->getType());
    }

    /**
     * @param Op\Expr\ArrowFunction|Op\Expr\Closure $expr
     *
     * @return OpCode[]
     */
    protected function compileAnonymousFunctionExpr($expr, Block $block): array
    {
        if ($this->shouldStubClosureForBootstrap()) {
            $resultSlot = $this->compileOperand($expr->result, $block, false);
            $nullSlot = $this->compileOperand(new Operand\Literal(null), $block, true);

            return [new OpCode(
                OpCode::TYPE_ASSIGN,
                $resultSlot,
                $resultSlot,
                $nullSlot
            )];
        }
        $func = $expr->func;
        $funcBlock = $this->compileCfgBlock($func->cfg, $func->params, $func);
        $op = new OpCode(
            OpCode::TYPE_CLOSURE,
            $this->compileOperand($expr->result, $block, false),
        );
        $op->block1 = $funcBlock;
        if ($expr instanceof Op\Expr\Closure) {
            foreach ($expr->useVars as $useVar) {
                if (!$useVar instanceof Operand\BoundVariable) {
                    continue;
                }
                $name = $this->boundVariableName($useVar);
                $slot = $funcBlock->getVarSlot($useVar, false);
                $funcBlock->closureCaptureSlots[$slot] = true;
                $op->closureCaptures[] = [
                    'name' => $name,
                    'slot' => $slot,
                    'byRef' => $useVar->byRef,
                ];
            }
        }

        return [$op];
    }

    private function boundVariableName(Operand\BoundVariable $useVar): string
    {
        if ($useVar->name instanceof Operand\Literal && is_string($useVar->name->value)) {
            return $useVar->name->value;
        }
        $this->throwCompileLogic('Closure use() variable name must be a literal');
    }

    protected function shouldStubClosureForBootstrap(): bool
    {
        return '1' === (string) getenv('PHP_COMPILER_VENDOR_PRELINK')
            || '1' === (string) getenv('PHP_COMPILER_SELFHOST_AOT');
    }

    protected function markFunctionGenerator(Block $block): void
    {
        if (null === $block->func || null === $this->seen) {
            return;
        }
        foreach ($this->seen as $cfgBlock) {
            $compiled = $this->seen[$cfgBlock];
            if ($compiled->func === $block->func) {
                $compiled->isGenerator = true;
            }
        }
    }

    /**
     * @return OpCode[]
     */
    protected function compileIsset(Op\Expr\Isset_ $expr, Block $block): array
    {
        assert(1 === count($expr->vars));
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $dimFetch = $this->findCoalesceArrayDimFetch($expr->vars[0], $block);
        [$containerSlot, $dimSlot] = null !== $dimFetch
            ? $this->resolveIssetTargetFromArrayDimFetch($dimFetch, $block)
            : $this->resolveIssetTarget($expr->vars[0], $block);

        return [new OpCode(
            OpCode::TYPE_ISSET,
            $resultSlot,
            $containerSlot,
            $dimSlot
        )];
    }

    protected function compileIncludeOp(Op\Expr\Include_ $expr, Block $block): OpCode
    {
        $resultSlot = null;
        if (!$block->returnTypeVoid && !$block->returnTypeNever) {
            if ($expr->result instanceof Operand\Temporary) {
                if ([] !== $expr->result->usages) {
                    $resultSlot = $this->compileOperand($expr->result, $block, false);
                }
            } else {
                $resultSlot = $this->compileOperand($expr->result, $block, false);
            }
        }

        $sourceFile = $expr->getFile() ?? '';
        $deploySpec = ConstStringFolder::tryParseDeployInclude($block->orig, $expr->expr, $sourceFile);
        if (null !== $deploySpec) {
            $pathIndex = count($block->deployIncludePaths);
            $block->deployIncludePaths[$pathIndex] = $deploySpec;
            $compilePath = $deploySpec['compile'] ?? '';
            $pathOperand = new Operand\Literal('' !== $compilePath ? $compilePath : ' ');
            $pathOperand->type = Type::string();

            return new OpCode(
                OpCode::TYPE_INCLUDE,
                $this->compileOperand($pathOperand, $block, true),
                $resultSlot,
                $pathIndex,
            );
        }

        $includePath = ConstStringFolder::foldForInclude($block->orig, $expr->expr, $sourceFile);
        if (null !== $includePath) {
            $resolved = IncludePathResolver::resolve($includePath, $expr->getFile());
            if (null !== $resolved) {
                $this->markCallerLocalsUsedByLiteralInclude($resolved, $block);
                $literal = new Operand\Literal($resolved);
                $literal->type = Type::string();
                $pathIndex = count($block->literalIncludePaths);
                $block->literalIncludePaths[$pathIndex] = $resolved;

                return new OpCode(
                    OpCode::TYPE_INCLUDE,
                    $this->compileOperand($literal, $block, true),
                    $resultSlot,
                    $pathIndex,
                );
            }
        }

        return new OpCode(
            OpCode::TYPE_INCLUDE,
            $this->compileOperand($expr->expr, $block, true),
            $resultSlot,
        );
    }

    protected function compileCoalesce(
        Op\Expr\BinaryOp\Coalesce $expr,
        Block $block,
        ?Operand $resultOverride = null
    ): Block {
        $resultOperand = $resultOverride ?? $expr->result;
        // php-cfg may mark the ?? result dead while it is still assigned on branch blocks (#99).
        if ($resultOperand instanceof Operand\Temporary && [] === $resultOperand->usages) {
            $resultOperand->usages[] = $resultOperand;
        }
        $resultSlot = $this->compileOperand($resultOperand, $block, false);

        $checkSlot = $this->compileBoolTemporary($block);
        $dimFetch = $this->findCoalesceArrayDimFetch($expr->left, $block);
        $issetTarget = null !== $dimFetch
            ? $this->resolveIssetTargetFromArrayDimFetch($dimFetch, $block)
            : $this->resolveCoalesceIssetTarget($expr->left, $block);
        if (null !== $issetTarget) {
            [$containerSlot, $dimSlot] = $issetTarget;
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ISSET,
                $checkSlot,
                $containerSlot,
                $dimSlot
            ));
        } else {
            $leftSlot = $this->compileOperand($expr->left, $block, true);
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ASSIGN,
                $resultSlot,
                $resultSlot,
                $leftSlot
            ));
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ISSET,
                $checkSlot,
                $leftSlot,
                null
            ));
        }

        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);

        $rightBlock = new Block($block->orig);
        $rightBlock->syntheticCfgBranch = true;
        $rightBlock->inheritUndefinedLocals = true;
        $rightBlock->inheritScopeFrom($block);
        $rightSlot = $this->compileOperand($expr->right, $rightBlock, true);
        $coalesceAssignTarget = $resultOverride ?? $expr->result;
        if (
            null !== $dimFetch
            && $this->operandsChainEqual($coalesceAssignTarget, $dimFetch->result)
        ) {
            $this->compileArrayDimFetchWrite($dimFetch, $rightBlock);
        }
        $rightBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $rightSlot
        ));

        $leftBlock = new Block($block->orig);
        $leftBlock->syntheticCfgBranch = true;
        $leftBlock->inheritUndefinedLocals = true;
        $leftBlock->inheritScopeFrom($block);
        if (null !== $issetTarget) {
            if (null !== $dimFetch) {
                $this->compileArrayDimFetchRead($dimFetch, $leftBlock);
                $leftSlot = $this->compileOperand($dimFetch->result, $leftBlock, true);
                $leftBlock->addOpCode(new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $resultSlot,
                    $resultSlot,
                    $leftSlot
                ));
            } else {
                $leftSlot = $this->compileOperand($expr->left, $leftBlock, true);
                $leftBlock->addOpCode(new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $resultSlot,
                    $resultSlot,
                    $leftSlot
                ));
            }
        }

        $leftJump = new OpCode(OpCode::TYPE_JUMP);
        $leftJump->block1 = $endBlock;
        $leftBlock->addOpCode($leftJump);
        $rightJump = new OpCode(OpCode::TYPE_JUMP);
        $rightJump->block1 = $endBlock;
        $rightBlock->addOpCode($rightJump);
        $endBlock->parents[] = $leftBlock;
        $endBlock->parents[] = $rightBlock;
        $endBlock->inheritScopeFrom($leftBlock);
        $endBlock->inheritScopeFrom($rightBlock);

        $coalesceOp = new OpCode(
            OpCode::TYPE_COALESCE,
            $resultSlot,
            $checkSlot
        );
        $coalesceOp->block1 = $leftBlock;
        $coalesceOp->block2 = $rightBlock;
        $coalesceOp->block3 = $endBlock;
        $block->addOpCode($coalesceOp);

        return $endBlock;
    }

    /**
     * Emit a read fetch in $block (used by ?? left branch when the stmt fetch was skipped).
     */
    private function compileArrayDimFetchRead(Op\Expr\ArrayDimFetch $fetch, Block $block): void
    {
        $block->addOpCode(new OpCode(
            OpCode::TYPE_ARRAY_DIM_FETCH,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileOperand($fetch->var, $block, true),
            null !== $fetch->dim ? $this->compileOperand($fetch->dim, $block, true) : null
        ));
    }

    /**
     * Emit a write fetch in $block (used by ??= right branch when the key is absent, issue #1235).
     */
    private function compileArrayDimFetchWrite(Op\Expr\ArrayDimFetch $fetch, Block $block): void
    {
        $block->addOpCode(new OpCode(
            OpCode::TYPE_ARRAY_DIM_FETCH_WRITE,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileOperand($fetch->var, $block, true),
            null !== $fetch->dim ? $this->compileOperand($fetch->dim, $block, true) : null
        ));
    }

    protected function compileNullsafePropertyFetch(Op\Expr\NullsafePropertyFetch $expr, Block $block): Block
    {
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $receiverSlot = $this->compileOperand($expr->var, $block, true);

        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);

        $nullBlock = new Block($block->orig);
        $nullBlock->inheritUndefinedLocals = true;
        $nullBlock->inheritScopeFrom($block);
        $nullLiteral = new Operand\Literal(null);
        $nullLiteral->type = Type::null();
        $nullValueSlot = $this->compileOperand($nullLiteral, $nullBlock, true);
        $nullBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $nullValueSlot
        ));
        $nullJump = new OpCode(OpCode::TYPE_JUMP);
        $nullJump->block1 = $endBlock;
        $nullBlock->addOpCode($nullJump);

        $fetchBlock = new Block($block->orig);
        $fetchBlock->inheritUndefinedLocals = true;
        $fetchBlock->inheritScopeFrom($block);
        $fetchBlock->addOpCode(new OpCode(
            OpCode::TYPE_PROPERTY_FETCH,
            $this->compileOperand($expr->result, $fetchBlock, false),
            $this->compileOperand($expr->var, $fetchBlock, true),
            $this->compileOperand($expr->name, $fetchBlock, true)
        ));
        $fetchJump = new OpCode(OpCode::TYPE_JUMP);
        $fetchJump->block1 = $endBlock;
        $fetchBlock->addOpCode($fetchJump);
        $endBlock->parents[] = $nullBlock;
        $endBlock->parents[] = $fetchBlock;

        $nullsafeOp = new OpCode(
            OpCode::TYPE_NULLSAFE,
            $resultSlot,
            $receiverSlot
        );
        $nullsafeOp->block1 = $nullBlock;
        $nullsafeOp->block2 = $fetchBlock;
        $nullsafeOp->block3 = $endBlock;
        $block->addOpCode($nullsafeOp);

        return $endBlock;
    }

    protected function compileNullsafeMethodCall(Op\Expr\NullsafeMethodCall $expr, Block $block): Block
    {
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $receiverSlot = $this->compileOperand($expr->var, $block, true);

        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);

        $nullBlock = new Block($block->orig);
        $nullBlock->inheritUndefinedLocals = true;
        $nullBlock->inheritScopeFrom($block);
        $nullLiteral = new Operand\Literal(null);
        $nullLiteral->type = Type::null();
        $nullValueSlot = $this->compileOperand($nullLiteral, $nullBlock, true);
        $nullBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $nullValueSlot
        ));
        $nullJump = new OpCode(OpCode::TYPE_JUMP);
        $nullJump->block1 = $endBlock;
        $nullBlock->addOpCode($nullJump);

        $fetchBlock = new Block($block->orig);
        $fetchBlock->inheritUndefinedLocals = true;
        $fetchBlock->inheritScopeFrom($block);
        $fetchBlock->addOpCode(new OpCode(
            OpCode::TYPE_METHODCALL_INIT,
            $this->compileOperand($expr->var, $fetchBlock, true),
            $this->compileOperand($expr->name, $fetchBlock, true)
        ));
        foreach ($this->compileCallArgSends($expr->args, $fetchBlock) as $send) {
            $fetchBlock->addOpCode($send);
        }
        if (!empty($expr->result->usages)) {
            $fetchBlock->addOpCode(new OpCode(
                OpCode::TYPE_FUNCCALL_EXEC_RETURN,
                $this->compileOperand($expr->result, $fetchBlock, false)
            ));
        } else {
            $fetchBlock->addOpCode(new OpCode(
                OpCode::TYPE_FUNCCALL_EXEC_NORETURN,
            ));
        }
        $fetchJump = new OpCode(OpCode::TYPE_JUMP);
        $fetchJump->block1 = $endBlock;
        $fetchBlock->addOpCode($fetchJump);
        $endBlock->parents[] = $nullBlock;
        $endBlock->parents[] = $fetchBlock;

        $nullsafeOp = new OpCode(
            OpCode::TYPE_NULLSAFE,
            $resultSlot,
            $receiverSlot
        );
        $nullsafeOp->block1 = $nullBlock;
        $nullsafeOp->block2 = $fetchBlock;
        $nullsafeOp->block3 = $endBlock;
        $block->addOpCode($nullsafeOp);

        return $endBlock;
    }

    protected function functionStaticStorageKey(\PHPCfg\Func $func, string $varName): string
    {
        return $this->resolveFuncDisplayName($func)."\0".$varName;
    }

    protected function resolveFuncDisplayName(\PHPCfg\Func $func): string
    {
        $name = $func->name;
        if ($name instanceof Operand\Literal && is_string($name->value)) {
            $name = $name->value;
        }
        if (!is_string($name)) {
            $this->throwCompileLogic('Function name must be a string literal for static storage key (#2286)');
        }
        $class = $func->class;
        if ($class instanceof Operand\Literal && is_string($class->value)) {
            $class = $class->value;
        }
        if (null !== $class && !is_string($class)) {
            $this->throwCompileLogic('Function class must be a string literal for static storage key (#2286)');
        }

        return null !== $class ? $class.'::'.$name : $name;
    }

    /**
     * @param Op\Terminal\StaticVar $terminal
     */
    protected function compileFunctionStaticVar(Op\Terminal $terminal, Block $block): OpCode
    {
        if (null === $block->func) {
            $this->throwCompileLogic('Function-local static requires a function context');
        }
        $varName = $this->resolveSimpleVariableName($terminal->var);
        $storageKey = $this->functionStaticStorageKey($block->func, $varName);
        $keyVar = new Variable(Variable::TYPE_STRING);
        $keyVar->string($storageKey);
        $keyOperand = new Operand\Literal($storageKey);
        $keyOperand->type = Type::string();
        $keySlot = $block->registerConstant($keyOperand, $keyVar);
        $defaultSlot = null;
        if (null !== $terminal->defaultVar) {
            $defaultSlot = $this->tryFoldFunctionStaticDefaultSlot($terminal, $block);
            if (null === $defaultSlot) {
                if (null !== $terminal->defaultBlock) {
                    $this->compileOps($terminal->defaultBlock->children, $block);
                }
                $defaultSlot = $this->compileOperand($terminal->defaultVar, $block, true);
                if (!isset($block->constants[$defaultSlot])) {
                    $this->throwCompileLogic(
                        'Function-local static initializer must be a compile-time literal in v1 (#2286)'
                    );
                }
            }
            $defaultVm = $block->constants[$defaultSlot];
            if (!$this->isAllowedFunctionStaticDefaultType($defaultVm->type)) {
                $this->throwCompileLogic(
                    'Function-local static initializer must be a compile-time literal in v1 (#2286)'
                );
            }
        }

        return new OpCode(
            OpCode::TYPE_DECLARE_FUNCTION_STATIC,
            $this->compileOperand($terminal->var, $block, false),
            $keySlot,
            $defaultSlot
        );
    }

    private function isAllowedFunctionStaticDefaultType(int $type): bool
    {
        return \in_array(
            $type,
            [
                Variable::TYPE_INTEGER,
                Variable::TYPE_STRING,
                Variable::TYPE_ARRAY,
                Variable::TYPE_BOOLEAN,
                Variable::TYPE_FLOAT,
                Variable::TYPE_NULL,
            ],
            true
        );
    }

    /**
     * @param Op\Terminal\StaticVar $terminal
     */
    protected function tryFoldFunctionStaticDefaultSlot(Op\Terminal $terminal, Block $block): ?int
    {
        if (null === $terminal->defaultBlock || null === $terminal->defaultVar) {
            return null;
        }
        $children = $terminal->defaultBlock->children;
        if (1 !== \count($children) || !$children[0] instanceof Op\Expr\Array_) {
            return null;
        }
        $vm = $this->tryBuildCompileTimeArrayFromExpr($children[0]);
        if (null === $vm) {
            return null;
        }
        $operand = new Operand\Temporary();

        return $block->registerConstant($operand, $vm);
    }

    protected function tryBuildCompileTimeArrayFromExpr(Op\Expr\Array_ $expr): ?Variable
    {
        $unpackFlags = property_exists($expr, 'unpack') ? $expr->unpack : [];
        $ht = new HashTable();
        $n = \count($expr->values);
        for ($i = 0; $i < $n; ++$i) {
            if (!empty($unpackFlags[$i])) {
                return null;
            }
            $valueVm = $this->vmVariableFromCfgLiteralOperand($expr->values[$i]);
            if (null === $valueVm) {
                return null;
            }
            $keyOp = $expr->keys[$i] ?? null;
            if (
                null === $keyOp
                || $keyOp instanceof Operand\NullOperand
                || ($keyOp instanceof Operand\Literal && null === $keyOp->value)
            ) {
                $ht->append($valueVm);
                continue;
            }
            $keyVm = $this->vmVariableFromCfgLiteralOperand($keyOp);
            if (null === $keyVm) {
                return null;
            }
            if ($keyVm->is(Variable::TYPE_INTEGER)) {
                $ht->addIndex($keyVm->toInt(), $valueVm);
            } elseif ($keyVm->is(Variable::TYPE_STRING)) {
                $ht->add($keyVm->toString(), $valueVm);
            } else {
                return null;
            }
        }
        $vmArray = new Variable(Variable::TYPE_ARRAY);
        $vmArray->array($ht);

        return $vmArray;
    }

    protected function vmVariableFromCfgLiteralOperand(Operand $operand): ?Variable
    {
        $literal = $this->unwrapCfgLiteralOperand($operand);
        if (null === $literal) {
            return null;
        }
        $mappedType = Variable::mapFromType($literal->type ?? Type::mixed());
        if (Variable::TYPE_UNDEFINED === $mappedType) {
            if (\is_int($literal->value)) {
                $mappedType = Variable::TYPE_INTEGER;
            } elseif (\is_float($literal->value)) {
                $mappedType = Variable::TYPE_FLOAT;
            } elseif (\is_string($literal->value)) {
                $mappedType = Variable::TYPE_STRING;
            } elseif (\is_bool($literal->value)) {
                $mappedType = Variable::TYPE_BOOLEAN;
            } elseif (null === $literal->value) {
                $mappedType = Variable::TYPE_NULL;
            }
        }
        $return = new Variable($mappedType);
        switch ($mappedType) {
            case Variable::TYPE_STRING:
                $return->string($literal->value);
                break;
            case Variable::TYPE_INTEGER:
                $return->int($literal->value);
                break;
            case Variable::TYPE_FLOAT:
                $return->float($literal->value);
                break;
            case Variable::TYPE_BOOLEAN:
                $return->bool($literal->value);
                break;
            case Variable::TYPE_NULL:
                break;
            default:
                return null;
        }

        return $return;
    }

    protected function unwrapCfgLiteralOperand(Operand $operand): ?Operand\Literal
    {
        while ($operand instanceof Operand\Temporary && null !== $operand->original) {
            $operand = $operand->original;
        }

        return $operand instanceof Operand\Literal ? $operand : null;
    }

    protected function resolveSimpleVariableName(Operand $var): string
    {
        while ($var instanceof Temporary) {
            if (null === $var->original) {
                break;
            }
            $var = $var->original;
        }
        if ($var instanceof Operand\Literal && is_string($var->value)) {
            return $var->value;
        }
        if (!$var instanceof Operand\Variable) {
            $this->throwCompileLogic('Expected a simple variable operand');
        }
        $name = $var->name;
        while ($name instanceof Temporary) {
            if (null === $name->original) {
                break;
            }
            $name = $name->original;
        }
        if ($name instanceof Operand\Literal && is_string($name->value)) {
            return $name->value;
        }

        $this->throwCompileLogic('Expected a simple variable name');
    }

    /**
     * @return ?array{0: int, 1: ?int}
     */
    protected function resolveCoalesceIssetTarget(Operand $operand, Block $block): ?array
    {
        $fetch = $this->findCoalesceArrayDimFetch($operand, $block);
        if (null !== $fetch) {
            return $this->resolveIssetTargetFromArrayDimFetch($fetch, $block);
        }
        if (null !== $this->unwrapVariableOperand($operand)) {
            return $this->resolveIssetTarget($operand, $block);
        }

        return null;
    }

    /**
     * @return ?Op\Expr\ArrayDimFetch
     */
    protected function findCoalesceArrayDimFetch(Operand $operand, Block $block): ?Op\Expr\ArrayDimFetch
    {
        $direct = $this->unwrapArrayDimFetch($operand);
        if (null !== $direct) {
            return $direct;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\ArrayDimFetch && $child->result === $operand) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: ?int}
     */
    protected function resolveIssetTargetFromArrayDimFetch(Op\Expr\ArrayDimFetch $fetch, Block $block): array
    {
        return [
            $this->compileOperand($fetch->var, $block, true),
            null !== $fetch->dim ? $this->compileOperand($fetch->dim, $block, true) : null,
        ];
    }

    protected function unwrapVariableOperand(Operand $operand): ?Operand\Variable
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Operand\Variable) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Operand\Variable) {
            return $operand;
        }

        return null;
    }

    /**
     * isset($a, $b, …) with short-circuit evaluation (PHP semantics).
     * Returns the block where compilation should continue.
     */
    protected function compileIssetMulti(Op\Expr\Isset_ $expr, Block $block): Block
    {
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $falseSlot = $this->compileBoolConstant($block, false);
        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);
        $falseBlock = new Block($block->orig);
        $falseBlock->inheritUndefinedLocals = true;
        $falseBlock->inheritScopeFrom($block);
        $falseBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $falseSlot
        ));
        $falseJump = new OpCode(OpCode::TYPE_JUMP);
        $falseJump->block1 = $endBlock;
        $falseBlock->addOpCode($falseJump);
        $endBlock->parents[] = $falseBlock;

        $current = $block;
        $vars = $expr->vars;
        $last = count($vars) - 1;
        foreach ($vars as $i => $var) {
            $dimFetch = $this->findCoalesceArrayDimFetch($var, $block);
            [$containerSlot, $dimSlot] = null !== $dimFetch
                ? $this->resolveIssetTargetFromArrayDimFetch($dimFetch, $block)
                : $this->resolveIssetTarget($var, $block);
            $checkSlot = $resultSlot;
            if ($i < $last) {
                $checkSlot = $this->compileBoolTemporary($current);
            }
            $current->addOpCode(new OpCode(
                OpCode::TYPE_ISSET,
                $checkSlot,
                $containerSlot,
                $dimSlot
            ));
            if ($i < $last) {
                $next = new Block($block->orig);
                $next->inheritUndefinedLocals = true;
                $next->inheritScopeFrom($current);
                $jump = new OpCode(OpCode::TYPE_JUMPIF, $checkSlot);
                $jump->block1 = $next;
                $jump->block2 = $falseBlock;
                $next->parents[] = $current;
                $falseBlock->parents[] = $current;
                $current->addOpCode($jump);
                $current = $next;
            }
        }

        $doneJump = new OpCode(OpCode::TYPE_JUMP);
        $doneJump->block1 = $endBlock;
        $current->addOpCode($doneJump);
        $endBlock->parents[] = $current;

        return $endBlock;
    }

    protected function compileBoolTemporary(Block $block): int
    {
        $operand = new Temporary;
        $operand->type = Type::bool();
        // JIT assignOperandValue skips operands with empty usages (#99 coalesce branches).
        $operand->usages[] = $operand;

        return $block->getVarSlot($operand, false);
    }

    protected function compileBoolConstant(Block $block, bool $value): int
    {
        $var = new Variable(Variable::TYPE_BOOLEAN);
        $var->bool($value);
        $operand = new Operand\Temporary;
        $operand->type = Type::bool();

        return $block->registerConstant($operand, $var);
    }

    /**
     * Normal try completion must run finally before merge; php-cfg jumps try straight to end (#2114).
     */
    private function rewriteTryMergeJumpsToFinally(Block $try, Block $merge, Block $finally): void
    {
        for ($i = 0; $i < $try->nOpCodes; ++$i) {
            $op = $try->opCodes[$i];
            if (OpCode::TYPE_JUMP === $op->type && $op->block1 === $merge) {
                $op->block1 = $finally;
            }
        }
    }

    /**
     * php-cfg TryCatch emits a Stmt_Jump into the try body; TYPE_TRY already enters it (#2084).
     */
    private function isRedundantTryEntryJump(Block $block, Block $target): bool
    {
        for ($i = $block->nOpCodes - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_CATCH === $op->type || OpCode::TYPE_FINALLY === $op->type) {
                continue;
            }
            if (OpCode::TYPE_TRY === $op->type) {
                return $op->block1 === $target;
            }

            break;
        }

        return false;
    }

    protected function encodeCatchTypeList(array $types): string
    {
        $encoded = [];
        foreach ($types as $name) {
            $encoded[] = strtolower(ltrim($name, '\\'));
        }

        return implode('|', $encoded);
    }

    /**
     * @return array{0: int, 1: ?int}
     */
    protected function resolveIssetTarget(Operand $operand, Block $block): array
    {
        $fetch = $this->unwrapArrayDimFetch($operand);
        if (null !== $fetch) {
            return [
                $this->compileOperand($fetch->var, $block, true),
                $this->compileOperand($fetch->dim, $block, true),
            ];
        }

        return [$this->compileOperand($operand, $block, true), null];
    }

    /**
     * True when the fetch result is only used as a write lvalue (assign or unset; issue #103, #1224).
     */
    protected function isArrayDimFetchForWrite(Op\Expr\ArrayDimFetch $fetch, Block $block): bool
    {
        foreach ($fetch->result->usages as $usage) {
            if ($usage instanceof Op\Expr\Assign && $usage->var === $fetch->result) {
                continue;
            }
            if ($usage instanceof Op\Terminal\Unset_ && $this->unsetTerminalUsesOperand($usage, $fetch->result)) {
                continue;
            }

            return false;
        }
        if (!empty($fetch->result->usages)) {
            return true;
        }
        // php-cfg often leaves operand->usages empty; fall back to the next stmt in this block.
        $children = $block->orig->children;
        foreach ($children as $i => $child) {
            if ($child !== $fetch) {
                continue;
            }
            if ($i + 1 >= count($children)) {
                break;
            }
            $next = $children[$i + 1];

            if ($next instanceof Op\Expr\Assign && $next->var === $fetch->result) {
                return true;
            }
            if ($next instanceof Op\Terminal\Unset_ && $this->unsetTerminalUsesOperand($next, $fetch->result)) {
                return true;
            }

            return false;
        }

        return false;
    }

    private function unsetTerminalUsesOperand(Op\Terminal\Unset_ $unset, Operand $operand): bool
    {
        foreach ($unset->exprs as $expr) {
            if ($expr === $operand) {
                return true;
            }
        }

        return false;
    }

    protected function unwrapArrayDimFetch(Operand $operand): ?Op\Expr\ArrayDimFetch
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\ArrayDimFetch) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\ArrayDimFetch) {
            return $operand;
        }

        return null;
    }

    protected function unwrapPropertyFetch(Operand $operand): ?Op\Expr\PropertyFetch
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\PropertyFetch) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\PropertyFetch) {
            return $operand;
        }

        return null;
    }

    protected function unwrapStaticPropertyFetch(Operand $operand): ?Op\Expr\StaticPropertyFetch
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\StaticPropertyFetch) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\StaticPropertyFetch) {
            return $operand;
        }

        return null;
    }

    protected function requireOperandSlot(?int $slot, string $context): int
    {
        if (null === $slot) {
            $this->throwCompileLogic('Missing operand slot for '.$context);
        }

        return $slot;
    }

    protected function compileOperand(?Operand $operand, Block $block, bool $isRead): ?int {
        if (null === $operand) {
            return null;
        }
        if ($operand instanceof Operand\NullOperand) {
            return null;
        } elseif ($operand instanceof Operand\Literal) {
            $mappedType = null !== $operand->type
                ? Variable::mapFromType($operand->type)
                : Variable::TYPE_UNDEFINED;
            if ($mappedType === Variable::TYPE_UNDEFINED) {
                if (is_int($operand->value)) {
                    $mappedType = Variable::TYPE_INTEGER;
                } elseif (is_float($operand->value)) {
                    $mappedType = Variable::TYPE_FLOAT;
                } elseif (is_string($operand->value)) {
                    $mappedType = Variable::TYPE_STRING;
                } elseif (is_bool($operand->value)) {
                    $mappedType = Variable::TYPE_BOOLEAN;
                } elseif (null === $operand->value) {
                    $mappedType = Variable::TYPE_NULL;
                }
            }
            $return = new Variable($mappedType);
            switch ($mappedType) {
                case Variable::TYPE_STRING:
                    $return->string($operand->value);
                    break;
                case Variable::TYPE_INTEGER:
                    $return->int($operand->value);
                    break;
                case Variable::TYPE_FLOAT:
                    $return->float($operand->value);
                    break;
                case Variable::TYPE_BOOLEAN:
                    $return->bool($operand->value);
                    break;
                case Variable::TYPE_NULL:
                    break;
                default:
                    $this->throwCompileLogic('Unknown Literal Operand Type: ' . ($operand->type ?? 'untyped'));
            }
            return $block->registerConstant($operand, $return);
        } elseif ($operand instanceof Operand\Variable) {
            if ($this->isDynamicVariableOperand($operand)) {
                $slot = $block->getVarSlot($operand, $isRead);
                $nameSlot = $this->compileOperand($operand->name, $block, true);
                $block->addOpCode(new OpCode(
                    OpCode::TYPE_VAR_FETCH,
                    $slot,
                    $nameSlot
                ));

                return $slot;
            }

            return $block->getVarSlot($operand, $isRead);
        } elseif ($operand instanceof Operand\Temporary) {
            return $block->getVarSlot($operand, $isRead);
        }
        $this->throwCompileLogic("Unknown Operand Type: " . $operand->getType());
    }

    private function isDynamicVariableOperand(Operand\Variable $operand): bool
    {
        return !$operand->name instanceof Operand\Literal;
    }

    /**
     * php-cfg may leave call result usages empty when the next op is `return $tmp` (#1885).
     */
    private function callNeedsReturnSlot(Operand $result, Block $block): bool
    {
        return !empty($result->usages) || $block->callResultFeedsReturn($result);
    }

    /**
     * `return foo()` lowers call opcodes then return; reuse FUNCCALL_EXEC_RETURN slot (#1885).
     */
    private function funcCallExecReturnSlotForReturn(Block $block, Operand $returnExpr): ?int
    {
        $n = $block->nOpCodes;
        if (0 === $n) {
            return null;
        }
        $last = $block->opCodes[$n - 1];
        if (OpCode::TYPE_FUNCCALL_EXEC_RETURN !== $last->type) {
            return null;
        }
        if (!$block->callResultFeedsReturn($returnExpr)) {
            return null;
        }

        return $last->arg1;
    }

    /**
     * @return list<OpCode>
     */
    protected function compileTerminal(Op\Terminal $terminal, Block $block): array {
        switch ($terminal->getType()) {
            case 'Terminal_Echo':
                $var = $this->compileOperand($terminal->expr, $block, true);

                return [new OpCode(
                    OpCode::TYPE_ECHO,
                    $var
                )];
            case 'Terminal_Return':
                if ($block->returnTypeNever) {
                    if (!is_null($terminal->expr)) {
                        $this->throwCompileError('A never-returning function must not return');
                    }
                    if ($this->neverFunctionHasAbnormalExitBeforeReturn($block->orig, $terminal)) {
                        return [];
                    }
                    $this->throwCompileError('A never-returning function must not return');
                }
                if (is_null($terminal->expr)) {
                    return [new OpCode(
                        OpCode::TYPE_RETURN_VOID
                    )];
                }
                if (
                    $block->returnTypeVoid
                    && !$terminal->expr instanceof Operand\Literal
                    && !$terminal->expr instanceof Operand\Variable
                ) {
                    // php-cfg may lower trailing include/call expr as return in void bodies.
                    return [new OpCode(
                        OpCode::TYPE_RETURN_VOID
                    )];
                }

                $callResultSlot = $this->funcCallExecReturnSlotForReturn($block, $terminal->expr);
                if (null !== $callResultSlot) {
                    return [new OpCode(OpCode::TYPE_RETURN, $callResultSlot)];
                }

                return [new OpCode(
                    OpCode::TYPE_RETURN,
                    $this->compileOperand($terminal->expr, $block, true)
                )];
            case 'Iterator_Reset':
                return [new OpCode(
                    OpCode::TYPE_ITER_RESET,
                    $this->compileOperand($terminal->var, $block, true)
                )];
            case 'Terminal_Throw':
                return [new OpCode(
                    OpCode::TYPE_THROW,
                    $this->compileOperand($terminal->expr, $block, true)
                )];
            case 'Terminal_Unset':
                $ops = [];
                foreach ($terminal->exprs as $unsetExpr) {
                    if ($unsetExpr instanceof Op\Expr\StaticPropertyFetch) {
                        $ops[] = new OpCode(
                            OpCode::TYPE_STATIC_PROPERTY_UNSET,
                            null,
                            $this->compileOperand($unsetExpr->class, $block, true),
                            $this->compileOperand($unsetExpr->name, $block, true)
                        );
                        continue;
                    }
                    [$containerSlot, $dimSlot] = $this->resolveUnsetTarget($unsetExpr, $block);
                    $ops[] = new OpCode(
                        OpCode::TYPE_UNSET,
                        null,
                        $containerSlot,
                        $dimSlot
                    );
                }

                return $ops;
            case 'Terminal_GlobalVar':
                $globalName = $this->resolveSimpleVariableName($terminal->var);
                $nameVar = new Variable(Variable::TYPE_STRING);
                $nameVar->string($globalName);
                $nameOperand = new Operand\Literal($globalName);
                $nameOperand->type = Type::string();
                $nameSlot = $block->registerConstant($nameOperand, $nameVar);
                return [new OpCode(
                    OpCode::TYPE_DECLARE_GLOBAL,
                    $this->compileOperand($terminal->var, $block, false),
                    $nameSlot
                )];
            case 'Terminal_StaticVar':
                return [$this->compileFunctionStaticVar($terminal, $block)];
            default:
                $this->throwCompileLogic("Unknown Terminal Type: " . $terminal->getType());
        }
    }



    /**
     * @return OpCode[]
     */
    protected function compileInstanceOf(Op\Expr\InstanceOf_ $expr, Block $block): array
    {
        return [new OpCode(
            OpCode::TYPE_INSTANCEOF,
            $this->compileOperand($expr->result, $block, false),
            $this->compileOperand($expr->expr, $block, true),
            $this->compileOperand($expr->class, $block, true)
        )];
    }

    /**
     * @return OpCode[]
     */
    protected function compileClassConstFetch(Op\Expr\ClassConstFetch $expr, Block $block): array
    {
        if (
            'parent' === strtolower((string) $this->literalScopeClassName($expr->class))
            && 'class' === strtolower((string) $this->literalScopeClassName($expr->name))
        ) {
            $this->throwCompileError('parent::class is not supported (issue #1858)');
        }

        return [new OpCode(
            OpCode::TYPE_CLASS_CONST_FETCH,
            $this->compileOperand($expr->result, $block, false),
            $this->compileOperand($expr->class, $block, true),
            $this->compileOperand($expr->name, $block, true)
        )];
    }

    /**
     * Lower PHP 8.1 first-class callables to VM/JIT callable representations (#1230).
     *
     * @return OpCode[]
     */
    protected function compileFirstClassCallable(Op\Expr\FirstClassCallable $expr, Block $block): array
    {
        $result = $this->compileOperand($expr->result, $block, false);
        // Numeric kinds: avoid php-cfg class const fetch during self-host bundle JIT (#1056).
        if (3 === $expr->kind) {
            $receiver = $this->compileOperand($expr->var, $block, true);
            $method = $this->compileOperand($expr->name, $block, true);

            return [
                new OpCode(
                    OpCode::TYPE_INIT_ARRAY,
                    $result,
                    $receiver,
                    $this->compileIntegerLiteralSlot(0, $block)
                ),
                new OpCode(
                    OpCode::TYPE_ADD_ARRAY_ELEMENT,
                    $result,
                    $method,
                    $this->compileIntegerLiteralSlot(1, $block)
                ),
            ];
        }

        if (1 === $expr->kind) {
            $callableSlot = $this->compileFirstClassFunctionNameSlot($expr->name, $block);
        } elseif (2 === $expr->kind) {
            $callableSlot = $this->compileFirstClassStaticNameSlot($expr->class, $expr->name, $block);
        } else {
            $this->throwCompileLogic('Unknown first-class callable kind');
        }

        return [new OpCode(
            OpCode::TYPE_ASSIGN,
            $result,
            $result,
            $callableSlot
        )];
    }

    private function compileFirstClassFunctionNameSlot(Operand $name, Block $block): int
    {
        if (!$name instanceof Operand\Literal) {
            $this->throwCompileLogic('First-class function callable name must be a literal');
        }

        return $this->compileStringLiteralSlot($name->value, $block);
    }

    private function compileFirstClassStaticNameSlot(?Operand $class, Operand $method, Block $block): int
    {
        if (!$class instanceof Operand\Literal || !$method instanceof Operand\Literal) {
            $this->throwCompileLogic('First-class static callable requires literal class and method names');
        }

        return $this->compileStringLiteralSlot($class->value.'::'.$method->value, $block);
    }

    private function compileStringLiteralSlot(string $value, Block $block): int
    {
        $var = new Variable(Variable::TYPE_STRING);
        $var->string($value);
        $operand = new Temporary();
        $operand->type = Type::string();

        return $block->registerConstant($operand, $var);
    }

    private function compileIntegerLiteralSlot(int $value, Block $block): int
    {
        $var = new Variable(Variable::TYPE_INTEGER);
        $var->int($value);
        $operand = new Temporary();
        $operand->type = Type::int();

        return $block->registerConstant($operand, $var);
    }

    protected function compileGlobalConst(Op\Terminal\Const_ $const, Block $block): OpCode
    {
        $this->compileOps($const->valueBlock->children, $block);

        return new OpCode(
            OpCode::TYPE_DECLARE_GLOBAL_CONST,
            $this->compileOperand($const->name, $block, true),
            $this->compileOperand($const->value, $block, true)
        );
    }

    protected function operandIsInvokableReceiver(Operand $operand, Block $block): bool
    {
        if ($this->operandHasObjectType($operand)) {
            return true;
        }
        if ($this->unwrapOperandChain($operand) instanceof Op\Expr\New_) {
            return true;
        }
        if (null === $block->orig) {
            return false;
        }
        $root = $this->unwrapOperandChain($operand);
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->var, $root)) {
                continue;
            }
            if ($this->operandDerivesFromNew($child->expr, $block)) {
                return true;
            }
            if ($this->operandDerivesFromClosure($child->expr)) {
                return true;
            }
            if ($this->operandHasObjectType($child->expr)) {
                return true;
            }
        }

        return false;
    }

    protected function operandDerivesFromClosure(Operand $operand): bool
    {
        $root = $this->unwrapOperandChain($operand);

        return $root instanceof Op\Expr\Closure || $root instanceof Op\Expr\ArrowFunction;
    }

    protected function operandsReferToSameVariable(Operand $a, Operand $b): bool
    {
        return $this->unwrapOperandChain($a) === $this->unwrapOperandChain($b);
    }

    protected function operandDerivesFromNew(Operand $operand, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $root = $this->unwrapOperandChain($operand);
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\New_) {
                continue;
            }
            if ($this->unwrapOperandChain($child->result) === $root) {
                return true;
            }
        }

        return false;
    }

    protected function unwrapOperandChain(Operand $operand): Operand
    {
        while ($operand instanceof Operand\Temporary && null !== $operand->original) {
            $operand = $operand->original;
        }

        return $operand;
    }

    protected function operandHasObjectType(Operand $operand): bool
    {
        $operand = $this->unwrapOperandChain($operand);

        return null !== $operand->type && Type::TYPE_OBJECT === $operand->type->type;
    }

    /**
     * @param list<Operand> $args
     *
     * @return list<OpCode>
     */
    protected function compileCallArgSends(array $args, Block $block): array
    {
        $sends = [];
        foreach ($args as $arg) {
            $valueSlot = $this->compileOperand($arg, $block, true);
            $nameSlot = null;
            $argName = $this->callArgName($arg);
            if (null !== $argName) {
                $nameOp = new Operand\Literal($argName);
                $nameOp->type = Type::string();
                $nameVar = new Variable(Variable::TYPE_STRING);
                $nameVar->string($argName);
                $nameSlot = $block->registerConstant($nameOp, $nameVar);
            }
            $unpackFlag = $this->callArgUnpack($arg) ? 1 : null;
            $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $valueSlot, $nameSlot, $unpackFlag);
        }

        return $sends;
    }

    private function callArgUnpack(Operand $arg): bool
    {
        return property_exists($arg, 'callArgUnpack') && true === $arg->callArgUnpack;
    }

    private function callArgName(Operand $arg): ?string
    {
        if (property_exists($arg, 'callArgName') && null !== $arg->callArgName) {
            $name = $arg->callArgName;

            return is_string($name) && '' !== $name ? $name : null;
        }

        return null;
    }

    /**
     * @return list<OpCode>
     */
    protected function compileArrayLiteral(Op\Expr\Array_ $expr, Block $block): array
    {
        $result = $this->compileOperand($expr->result, $block, false);
        if (empty($expr->values)) {
            return [new OpCode(OpCode::TYPE_INIT_ARRAY, $result)];
        }

        $return = [];
        $started = false;
        $unpackFlags = property_exists($expr, 'unpack') ? $expr->unpack : [];
        for ($i = 0, $n = count($expr->values); $i < $n; ++$i) {
            if (!empty($unpackFlags[$i])) {
                if (!$started) {
                    $return[] = new OpCode(OpCode::TYPE_INIT_ARRAY, $result);
                    $started = true;
                }
                $return[] = new OpCode(
                    OpCode::TYPE_ARRAY_SPREAD,
                    $result,
                    $this->compileOperand($expr->values[$i], $block, true)
                );
                continue;
            }

            $valueSlot = $this->compileOperand($expr->values[$i], $block, true);
            $keySlot = $this->compileOperand($expr->keys[$i], $block, true);
            if (!$started) {
                $return[] = new OpCode(OpCode::TYPE_INIT_ARRAY, $result, $valueSlot, $keySlot);
                $started = true;
            } else {
                $return[] = new OpCode(OpCode::TYPE_ADD_ARRAY_ELEMENT, $result, $valueSlot, $keySlot);
            }
        }

        return $return;
    }

    protected function compileMethodCallOpcodes(
        ?int $receiver,
        ?int $methodName,
        array $args,
        Operand $result,
        Block $block
    ): array {
        $return = [
            new OpCode(
                OpCode::TYPE_METHODCALL_INIT,
                $receiver,
                $methodName
            ),
        ];
        foreach ($this->compileCallArgSends($args, $block) as $send) {
            $return[] = $send;
        }
        if ($this->callNeedsReturnSlot($result, $block)) {
            $return[] = new OpCode(
                OpCode::TYPE_FUNCCALL_EXEC_RETURN,
                $this->compileOperand($result, $block, false)
            );
        } else {
            $return[] = new OpCode(
                OpCode::TYPE_FUNCCALL_EXEC_NORETURN,
            );
        }

        return $return;
    }

    protected function compileFuncCall(?int $name, array $args, Operand $result, Block $block): array
    {
        $folded = $this->tryCompileDefineAsGlobalConst($name, $args, $result, $block);
        if (null !== $folded) {
            return $folded;
        }

        $callName = $this->tryFoldVariableFunctionName($name, $block) ?? $name;

        $return = [new OpCode(OpCode::TYPE_FUNCCALL_INIT, $callName)];
        foreach ($this->compileCallArgSends($args, $block) as $send) {
            $return[] = $send;
        }
        if ($this->callNeedsReturnSlot($result, $block)) {
            $return[] = new OpCode(OpCode::TYPE_FUNCCALL_EXEC_RETURN, $this->compileOperand($result, $block, false));
        } else {
            $return[] = new OpCode(OpCode::TYPE_FUNCCALL_EXEC_NORETURN);
        }
        return $return;
    }

    /**
     * Fold $fn = 'name'; $fn(...) to a literal callee when the name is a compile-time string (#56).
     *
     * Follows TYPE_ASSIGN chains so first-class callables (`strlen(...)`, `C::m(...)`, #1363) fold too.
     */
    protected function tryFoldVariableFunctionName(?int $nameSlot, Block $block): ?int
    {
        if (null === $nameSlot) {
            return null;
        }
        $name = $this->resolveCompileTimeStringSlot($nameSlot, $block);
        if (null === $name) {
            return null;
        }
        $lit = new Literal($name);
        $lit->type = Type::string();

        return $this->compileOperand($lit, $block, true);
    }

    /**
     * Resolve a scope slot to a compile-time string via constants or assign chains (#1363).
     */
    protected function resolveCompileTimeStringSlot(int $slot, Block $block, array &$visited = []): ?string
    {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;
        if (isset($block->constants[$slot])) {
            $const = $block->constants[$slot];
            if (Variable::TYPE_STRING !== $const->type) {
                return null;
            }

            return $const->toString();
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type || $op->arg2 !== $slot) {
                continue;
            }
            $resolved = $this->resolveCompileTimeStringSlot((int) $op->arg3, $block, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = $this->resolveCompileTimeStringSlot($slot, $parent, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Lower define('NAME', literal) to compile-time global constant registration (issue #204).
     *
     * @return list<OpCode>|null
     */
    protected function tryCompileDefineAsGlobalConst(
        ?int $name,
        array $args,
        Operand $result,
        Block $block
    ): ?array {
        if (null === $name) {
            return null;
        }
        $nameOp = $block->getOperand($name);
        if (!$nameOp instanceof Operand\Literal || 'define' !== $nameOp->value) {
            return null;
        }
        if (count($args) < 2) {
            return null;
        }
        $constNameArg = $args[0];
        $valueArg = $args[1];
        if (!$constNameArg instanceof Operand\Literal || !$valueArg instanceof Operand\Literal) {
            return null;
        }
        if (Variable::TYPE_STRING !== Variable::mapFromType($constNameArg->type)) {
            return null;
        }
        $constNameSlot = $this->compileOperand($constNameArg, $block, true);
        $valueSlot = $this->compileOperand($valueArg, $block, true);
        if (!isset($block->constants[$constNameSlot], $block->constants[$valueSlot])) {
            return null;
        }
        $ops = [new OpCode(
            OpCode::TYPE_DECLARE_GLOBAL_CONST,
            $constNameSlot,
            $valueSlot
        )];
        if (!empty($result->usages)) {
            $trueVar = new Variable(Variable::TYPE_BOOLEAN);
            $trueVar->bool(true);
            $trueOperand = new Temporary;
            $trueOperand->type = Type::bool();
            $trueSlot = $block->registerConstant($trueOperand, $trueVar);
            $ops[] = new OpCode(
                OpCode::TYPE_ASSIGN,
                $this->compileOperand($result, $block, false),
                $this->compileOperand($result, $block, false),
                $trueSlot
            );
        }

        return $ops;
    }

    /**
     * Literal includes read caller locals by name; php-cfg may mark those assigns dead (#568).
     */
    private function markCallerLocalsUsedByLiteralInclude(string $path, Block $block): void
    {
        if (!is_file($path)) {
            return;
        }
        $code = file_get_contents($path);
        if (false === $code || '' === $code) {
            return;
        }
        foreach ($block->scopedOperands() as $operand) {
            $name = OperandName::resolve($operand);
            if (null === $name || Superglobals::isSuperglobalName($name)) {
                continue;
            }
            if (!preg_match('/\\$'.preg_quote($name, '/').'\\b/', $code)) {
                continue;
            }
            if ($operand instanceof Temporary && [] === $operand->usages) {
                $operand->usages[] = $operand;
            }
        }
    }

}
