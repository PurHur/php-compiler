<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\AOT\ExternalMethodBind;
use PHPCompiler\Block;
use PHPCompiler\OpCode;

/**
 * Capacity gate for spine split-TU hubs that NestedJIT OOMs or traps under 8g (#36387).
 *
 * Measured on master:
 * - CompilerVersion.php (181 KB / 355 methods) emits under 8g in ~26s.
 * - Runtime.php (60 KB / 57 methods) grew ~200 MiB/10s and OOMed — NestedJIT of method
 *   bodies (initParsePipeline visitor ctors, parse/compile/standalone) was the sink.
 * - VM\Variable / HashTable / TypeCheck / Context / ErrorReporter hit NestedJIT traps
 *   (ARG_SEND, string-offset TYPE_VALUE, try/catch null insert block, int1←__string__*).
 * - AOT\AotEmitFastExit traps on try/catch null insert; BuildTiming / HelperRuntimeCache /
 *   ProjectGraph OOM under 1536M; ComposerVendorMap dies on computed include (#36382).
 *   Single-file bisect 2026-09-04: 9/14 AOT units OK without demote; 5 need this gate.
 * - Compiler\* peer TUs (lib/Compiler/): BITWISE_AND NestedJIT gap, InheritanceVariance /
 *   TraitClassConstConflictCheck "argument must be a string", host isInlineExprCallArgProducer
 *   null Op, and OOM under 1536M — measured 3/8 OK without demote (2026-09-04).
 * - Web\* peer TUs: OOM + preg_replace_callback closure deferral under SPINE_CHUNK — 0/2 OK.
 * - Ast\* peer TUs: preg_replace_callback closure deferral under SPINE_CHUNK — 2/4 OK without
 *   demote (lib-ast-00 / lib-ast-02, 2026-09-04).
 * - Cli\* peer TU: NestedJIT OOM at 1536M on PhpcBuild/PhpcRun cluster — 0/1 OK.
 * - SourcePreprocessor\* (PropertyHooks): NestedJIT "argument must be a string" on
 *   locateHookSyntaxErrorInBody — 0/1 OK.
 * - JIT\* peer TUs (lib/JIT + Builtin): isset-on-object-offset, goto-resume seal, and
 *   segfault (rc=139) under NestedJIT — measured 9/12 OK on first 12 of 100 chunks
 *   without demote (2026-09-04).
 * - Top-level Block.php (159 KB): NestedJIT assign trap hashtable→native-long
 *   ("Cannot assign operands of different types (yet): 135, 1") — omitted from
 *   spine-chunk-core-requires until demoted (2026-09-04).
 * - PHPCompiler\ext\* peer TUs (e.g. ext/bcmath class+JIT packs): NestedJIT segfault
 *   rc=139 under SPINE_CHUNK — measured spine-ext-bcmath-00 / ext-bcmath-01 FAIL before
 *   demote; 2/2 OK in 6s after (2026-09-04).
 * - Top-level VM.php (1.1 MB): NestedJIT OOM without demote; emits under 1536M after.
 * - Top-level Compiler.php (2.1 MB) / JIT.php (1.0 MB): host CFG construction OOMs at
 *   1536M even with empty method bodies — skipped from spine plans (see chunk-plan).
 *
 * Emptying those bodies (probe) emits .o files in seconds. Host-lowering Runtime::initParsePipeline
 * was already known to hang Zend rebuilds for hours ({@see RuntimeInitParsePipeline}).
 *
 * Under {@see ExternalMethodBind::spineChunkMode()}, replace Runtime + Block + top-level VM +
 * every
 * {@see \PHPCompiler\VM} / {@see \PHPCompiler\AOT} / {@see \PHPCompiler\Compiler} (sub-NS) /
 * {@see \PHPCompiler\Web} / {@see \PHPCompiler\Ast} / {@see \PHPCompiler\Cli} /
 * {@see \PHPCompiler\SourcePreprocessor} / {@see \PHPCompiler\JIT} (sub-NS) /
 * {@see \PHPCompiler\ext} class method CFG
 * with a void-return stub before {@see \PHPCompiler\JIT::compileBlock} so hub ClassEntry +
 * method symbols still land in the .o / peer manifest. Real bodies stay on C-floor helpers
 * (RuntimeInitParsePipeline / RuntimeParseM5Native), NestedVM object:: proxies, or later
 * peer-bound non-demoted TUs. Does not demote top-level {@see \PHPCompiler\Compiler} /
 * {@see \PHPCompiler\CompilerVersion} / {@see \PHPCompiler\JIT} — Compiler/JIT need file
 * splits before host CFG fits under 8g; CompilerVersion already emits live.
 */
final class SpineChunkRuntimeMethodDemote
{
    public static function shouldDemote(string $displayClassLc): bool
    {
        if (!ExternalMethodBind::spineChunkMode()) {
            return false;
        }

        $lc = strtolower(ltrim($displayClassLc, '\\'));
        if (
            'phpcompiler\\runtime' === $lc
            || 'phpcompiler\\block' === $lc
            || 'phpcompiler\\vm' === $lc
        ) {
            return true;
        }

        // Packed hubs keep growing NestedJIT gaps across VM/AOT/Compiler/Web/Ast/Cli/JIT/ext…
        return str_starts_with($lc, 'phpcompiler\\vm\\')
            || str_starts_with($lc, 'phpcompiler\\aot\\')
            || str_starts_with($lc, 'phpcompiler\\compiler\\')
            || str_starts_with($lc, 'phpcompiler\\web\\')
            || str_starts_with($lc, 'phpcompiler\\ast\\')
            || str_starts_with($lc, 'phpcompiler\\cli\\')
            || str_starts_with($lc, 'phpcompiler\\sourcepreprocessor\\')
            || str_starts_with($lc, 'phpcompiler\\jit\\')
            || str_starts_with($lc, 'phpcompiler\\ext\\');
    }

    /**
     * Replace method CFG with a single TYPE_RETURN_VOID so NestedJIT does not walk visitors.
     *
     * Callers still declare the LLVM function with the original arity; {@see self::isDemotedStub()}
     * skips compileBlockInternal's arg prologue so `int ...$types` packing cannot assign a
     * hashtable into a NATIVE_LONG param slot (Block.php under SPINE_CHUNK, #36387).
     */
    public static function demoteMethodBlock(Block $methodBlock, string $methodLc): void
    {
        $methodBlock->opCodes = [];
        $methodBlock->blocks = [];
        $methodBlock->addOpCode(new OpCode(OpCode::TYPE_RETURN_VOID));
        // Breadcrumb for capacity probes / chunk logs (#36387).
        Progress::noteFunction('spine_chunk_runtime_demote:'.$methodLc);
    }

    /**
     * True when {@see demoteMethodBlock()} replaced the body (single void return).
     */
    public static function isDemotedStub(Block $methodBlock): bool
    {
        return 1 === \count($methodBlock->opCodes)
            && OpCode::TYPE_RETURN_VOID === $methodBlock->opCodes[0]->type
            && [] === $methodBlock->blocks;
    }
}
