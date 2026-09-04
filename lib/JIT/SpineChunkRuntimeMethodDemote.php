<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\AOT\ExternalMethodBind;
use PHPCompiler\Block;
use PHPCompiler\OpCode;

/**
 * Capacity gate for spine split-TU hubs that NestedJIT {@see \PHPCompiler\Runtime} (#36387).
 *
 * Measured on master: CompilerVersion.php (181 KB / 355 methods) emits under 8g in ~26s, but
 * Runtime.php (60 KB / 57 methods) grows ~200 MiB/10s and OOMs — NestedJIT of method bodies
 * (initParsePipeline visitor ctors, parse/compile/standalone) is the sink, not source size.
 * Emptying those bodies (probe) emits a 2.2 MB .o in 5s. Host-lowering Runtime::initParsePipeline
 * was already known to hang Zend rebuilds for hours ({@see RuntimeInitParsePipeline}).
 *
 * Under {@see ExternalMethodBind::spineChunkMode()}, replace Runtime method CFGs with a
 * void-return stub before {@see \PHPCompiler\JIT::compileBlock} so the hub ClassEntry + method
 * symbols still land in the .o / peer manifest. Real bodies stay on C-floor helpers
 * (RuntimeInitParsePipeline / RuntimeParseM5Native) or later peer-bound TUs.
 */
final class SpineChunkRuntimeMethodDemote
{
    private const RUNTIME_CLASS_LC = 'phpcompiler\\runtime';

    public static function shouldDemote(string $displayClassLc): bool
    {
        if (!ExternalMethodBind::spineChunkMode()) {
            return false;
        }

        return self::RUNTIME_CLASS_LC === strtolower(ltrim($displayClassLc, '\\'));
    }

    /**
     * Replace method CFG with a single TYPE_RETURN_VOID so NestedJIT does not walk visitors.
     */
    public static function demoteMethodBlock(Block $methodBlock, string $methodLc): void
    {
        $methodBlock->opCodes = [];
        $methodBlock->blocks = [];
        $methodBlock->addOpCode(new OpCode(OpCode::TYPE_RETURN_VOID));
        // Breadcrumb for capacity probes / chunk logs (#36387).
        Progress::noteFunction('spine_chunk_runtime_demote:'.$methodLc);
    }
}
