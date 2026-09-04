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
 * - Top-level Builtin* / OpCode / ModuleAbstract / Frame / Config: NestedJIT SEGV (rc=139)
 *   or LLVM Type OOM under 1536M — measured 2026-09-04 (BuiltinParamNames 33s SEGV;
 *   Config 168s then Allowed memory exhausted). Doctor.php fails host CFG
 *   (isInlineExprCallArgProducer null Op) before JIT demote — skipped in chunk-plan.
 * - Func\* / Cfg\* / Lint\* / Visitor\*: NestedJIT SEGV on Internal/PHP/Linter/
 *   OpSubBlockAccess/VoidCastResolver — measured 2026-09-04.
 *
 * Emptying those bodies (probe) emits .o files in seconds. Host-lowering Runtime::initParsePipeline
 * was already known to hang Zend rebuilds for hours ({@see RuntimeInitParsePipeline}).
 *
 * Under {@see ExternalMethodBind::spineChunkMode()}, replace Runtime + Block + top-level VM +
 * Builtin* / OpCode / ModuleAbstract / Frame / Config + every
 * {@see \PHPCompiler\VM} / {@see \PHPCompiler\AOT} / {@see \PHPCompiler\Compiler} (sub-NS) /
 * {@see \PHPCompiler\Web} / {@see \PHPCompiler\Ast} / {@see \PHPCompiler\Cli} /
 * {@see \PHPCompiler\SourcePreprocessor} / {@see \PHPCompiler\JIT} (sub-NS) /
 * {@see \PHPCompiler\ext} / {@see \PHPCompiler\Func} / {@see \PHPCompiler\Cfg} /
 * {@see \PHPCompiler\Lint} / {@see \PHPCompiler\Visitor} class method CFG
 * with a void-return stub before {@see \PHPCompiler\JIT::compileBlock} so hub ClassEntry +
 * method symbols still land in the .o / peer manifest. Real bodies stay on C-floor helpers
 * (RuntimeInitParsePipeline / RuntimeParseM5Native), NestedVM object:: proxies, or later
 * peer-bound non-demoted TUs. Does not demote top-level {@see \PHPCompiler\Compiler} /
 * {@see \PHPCompiler\CompilerVersion} / {@see \PHPCompiler\JIT} — Compiler/JIT need file
 * splits before host CFG fits under 8g; CompilerVersion already emits live.
 * {@see self::rewriteSource()} hollows demoted class bodies before CFG when
 * SourceBundler keeps the entry filename.
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
            || 'phpcompiler\\opcode' === $lc
            || 'phpcompiler\\moduleabstract' === $lc
            || 'phpcompiler\\frame' === $lc
            || 'phpcompiler\\config' === $lc
            || str_starts_with($lc, 'phpcompiler\\builtin')
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
            || str_starts_with($lc, 'phpcompiler\\ext\\')
            || str_starts_with($lc, 'phpcompiler\\func\\')
            || str_starts_with($lc, 'phpcompiler\\cfg\\')
            || str_starts_with($lc, 'phpcompiler\\lint\\')
            || str_starts_with($lc, 'phpcompiler\\visitor\\');
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

    public static function rewriteSource(string $code, string $filename = 'unknown'): string
    {
        $marker = getenv('PHP_COMPILER_SPINE_CHUNK_DEMOTE_LOG');
        if (is_string($marker) && '' !== $marker) {
            @file_put_contents(
                $marker,
                sprintf(
                    "enter\t%s\tspine=%s\tbytes=%d\n",
                    $filename,
                    ExternalMethodBind::spineChunkMode() ? '1' : '0',
                    strlen($code)
                ),
                FILE_APPEND
            );
        }
        if (!ExternalMethodBind::spineChunkMode()) {
            return $code;
        }

        $hollowed = self::hollowDemotedClassesInSource($code);
        if (is_string($marker) && '' !== $marker && $hollowed !== $code) {
            @file_put_contents(
                $marker,
                sprintf(
                    "hollow\t%s\tin=%d\tout=%d\n",
                    $filename,
                    strlen($code),
                    strlen($hollowed)
                ),
                FILE_APPEND
            );
        }

        return $hollowed;
    }

    /**
     * lib/Compiler.php → PHPCompiler\Compiler (best-effort; bundler may not use this).
     */
    public static function fqcnFromFilename(string $filename): ?string
    {
        $path = str_replace('\\', '/', $filename);
        $pos = strrpos($path, '/lib/');
        if (false === $pos) {
            if (str_starts_with($path, 'lib/')) {
                $rel = substr($path, 4);
            } else {
                return null;
            }
        } else {
            $rel = substr($path, $pos + 5);
        }
        if (!str_ends_with($rel, '.php')) {
            return null;
        }
        $rel = substr($rel, 0, -4);
        if ('' === $rel || str_contains($rel, '..')) {
            return null;
        }

        return 'PHPCompiler\\'.str_replace('/', '\\', $rel);
    }

    public static function shortClassName(string $fqcn): string
    {
        $fqcn = ltrim($fqcn, '\\');
        $pos = strrpos($fqcn, '\\');

        return false === $pos ? $fqcn : substr($fqcn, $pos + 1);
    }

    /**
     * @deprecated use hollowDemotedClassesInSource — kept for unit tests targeting one class
     */
    public static function hollowClassMethodBodies(string $code, string $className): string
    {
        // Force a single short-name match by wrapping shouldDemote via temporary namespace scan
        // that only hollows $className (tests pass unqualified Compiler / JIT).
        return self::hollowDemotedClassesInSource($code, strtolower($className));
    }

    /**
     * Hollow method bodies for every demoted class in $code.
     *
     * @param string|null $onlyShortLc when set, only hollow this short class name (test helper)
     */
    public static function hollowDemotedClassesInSource(string $code, ?string $onlyShortLc = null): string
    {
        $tokens = token_get_all($code);
        $n = \count($tokens);
        $out = '';
        $i = 0;
        $namespace = '';
        $namespaceBraceDepth = 0; // >0 when inside `namespace Foo {`
        $inTargetClass = false;
        $classBraceDepth = 0;
        $prevMeaningful = null;

        while ($i < $n) {
            $tok = $tokens[$i];
            $text = \is_array($tok) ? $tok[1] : $tok;

            if (!$inTargetClass) {
                // namespace Foo;  /  namespace Foo {
                if (\is_array($tok) && T_NAMESPACE === $tok[0]) {
                    $out .= $text;
                    ++$i;
                    $nsParts = [];
                    while ($i < $n) {
                        $t2 = $tokens[$i];
                        $s2 = \is_array($t2) ? $t2[1] : $t2;
                        $out .= $s2;
                        ++$i;
                        if (\is_array($t2) && T_STRING === $t2[0]) {
                            $nsParts[] = $t2[1];
                            continue;
                        }
                        if (\is_array($t2) && T_NS_SEPARATOR === $t2[0]) {
                            continue;
                        }
                        if ('{' === $s2) {
                            $namespace = implode('\\', $nsParts);
                            $namespaceBraceDepth = 1;
                            break;
                        }
                        if (';' === $s2) {
                            $namespace = implode('\\', $nsParts);
                            $namespaceBraceDepth = 0;
                            break;
                        }
                    }
                    $prevMeaningful = null;
                    continue;
                }

                if ($namespaceBraceDepth > 0) {
                    if ('{' === $text) {
                        ++$namespaceBraceDepth;
                        $out .= $text;
                        ++$i;
                        continue;
                    }
                    if ('}' === $text) {
                        --$namespaceBraceDepth;
                        $out .= $text;
                        ++$i;
                        if ($namespaceBraceDepth <= 0) {
                            $namespace = '';
                            $namespaceBraceDepth = 0;
                        }
                        continue;
                    }
                }

                $isClassKeyword = \is_array($tok) && T_CLASS === $tok[0];
                if ($isClassKeyword && T_DOUBLE_COLON !== $prevMeaningful) {
                    $nameIdx = self::nextMeaningfulIndex($tokens, $i + 1);
                    if ($nameIdx < $n) {
                        $nameTok = $tokens[$nameIdx];
                        if (\is_array($nameTok) && T_STRING === $nameTok[0]) {
                            $short = $nameTok[1];
                            $fqcn = '' === $namespace
                                ? $short
                                : $namespace.'\\'.$short;
                            $should = null === $onlyShortLc
                                ? SpineChunkRuntimeMethodDemote::shouldDemote($fqcn)
                                : (strtolower($short) === $onlyShortLc);
                            if ($should) {
                                while ($i < $n) {
                                    $t2 = $tokens[$i];
                                    $s2 = \is_array($t2) ? $t2[1] : $t2;
                                    $out .= $s2;
                                    if (!\is_array($t2) || (T_WHITESPACE !== $t2[0] && T_COMMENT !== $t2[0] && T_DOC_COMMENT !== $t2[0])) {
                                        $prevMeaningful = \is_array($t2) ? $t2[0] : $t2;
                                    }
                                    ++$i;
                                    if ('{' === $s2) {
                                        $inTargetClass = true;
                                        $classBraceDepth = 1;
                                        break;
                                    }
                                    if (';' === $s2) {
                                        break;
                                    }
                                }
                                continue;
                            }
                        }
                    }
                }

                $out .= $text;
                if (!\is_array($tok) || (T_WHITESPACE !== $tok[0] && T_COMMENT !== $tok[0] && T_DOC_COMMENT !== $tok[0])) {
                    $prevMeaningful = \is_array($tok) ? $tok[0] : $tok;
                }
                ++$i;
                continue;
            }

            // Inside a demoted class
            if ('{' === $text) {
                ++$classBraceDepth;
                $out .= $text;
                ++$i;
                continue;
            }
            if ('}' === $text) {
                --$classBraceDepth;
                $out .= $text;
                ++$i;
                if ($classBraceDepth <= 0) {
                    $inTargetClass = false;
                    $classBraceDepth = 0;
                }
                continue;
            }

            if (1 === $classBraceDepth && \is_array($tok) && T_FUNCTION === $tok[0]) {
                $out .= $text;
                ++$i;
                while ($i < $n) {
                    $t2 = $tokens[$i];
                    $s2 = \is_array($t2) ? $t2[1] : $t2;
                    if ('{' === $s2) {
                        $out .= '{}';
                        ++$i;
                        $depth = 1;
                        while ($i < $n && $depth > 0) {
                            $t3 = $tokens[$i];
                            $s3 = \is_array($t3) ? $t3[1] : $t3;
                            if ('{' === $s3) {
                                ++$depth;
                            } elseif ('}' === $s3) {
                                --$depth;
                            }
                            ++$i;
                        }
                        break;
                    }
                    $out .= $s2;
                    ++$i;
                    if (';' === $s2) {
                        break;
                    }
                }
                continue;
            }

            $out .= $text;
            ++$i;
        }

        return $out;
    }

    /**
     * @param array<int, string|array{0:int,1:string,2?:int}> $tokens
     */
    private static function nextMeaningfulIndex(array $tokens, int $from): int
    {
        $n = \count($tokens);
        for ($i = $from; $i < $n; ++$i) {
            $tok = $tokens[$i];
            if (!\is_array($tok)) {
                return $i;
            }
            if (T_WHITESPACE === $tok[0] || T_COMMENT === $tok[0] || T_DOC_COMMENT === $tok[0]) {
                continue;
            }

            return $i;
        }

        return $n;
    }
}
