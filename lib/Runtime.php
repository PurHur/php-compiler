<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Parser;
use PHPCfg\Traverser;
use PHPCfg\LivenessDetector as CfgLivenessDetector;
use PHPCfg\Visitor;
use PHPCfg\Script;
use PHPTypes\TypeReconstructor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\ParserFactory;
use PHPTypes\State;
use PHPCompiler\VM\Optimizer;
use PHPCompiler\VM\Context as VMContext;
use PHPCompiler\VM\ObjectRegistry;
use PHPCompiler\JIT\Context as JITContext;
use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPCompiler\Ast\GroupUseStripper;
use PHPCompiler\Ast\SealedClassAnnotator;
use PHPCompiler\Ast\SealedClassPreprocessor;
use PHPCompiler\Ast\PipeOperatorDesugar;
use PHPCompiler\Web\Superglobals;
use PHPCompiler\Lint\LintCompiler;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\ShutdownQueue;

class Runtime {
    const MODE_NORMAL   = 0b0001;
    const MODE_AOT      = 0b0010;

    public Compiler $compiler;
    public Parser $parser;
    public Traverser $preprocessor;
    public Traverser $postprocessor;
    private Ast\AbstractEnumMarker $abstractEnumMarker;
    public CfgLivenessDetector $detector;
    public Optimizer $assignOpResolver;
    public VMContext $vmContext;
    public ?VM $vm = null;
    private ?JITContext $jitContext = null;
    private ?JIT $jit = null;
    private bool $jitLoadedFromDiskCache = false;
    private ?string $jitCompileCacheKey = null;
    public array $modules = [];
    public int $mode;
    private SealedClassAnnotator $sealedClassAnnotator;
    public ?string $debugFile = null;

    public TypeReconstructor $typeReconstructor;

    /** Last parse/compile failure for native M3 emit bridge (#3037). */
    private static ?string $lastParseFailure = null;

    /**
     * Whether parse/compile null diagnostics should be emitted (#2988).
     */
    public static function isParseDiagEnabled(): bool
    {
        if (JIT\EmitTuMode::isMinimalRuntime()) {
            return true;
        }
        if (!\function_exists('getenv')) {
            return false;
        }
        foreach (['PHP_COMPILER_PARSE_DIAG', 'PHP_COMPILER_SELFHOST_AOT', 'PHP_COMPILER_M3_COMPILE_MODE'] as $name) {
            $value = getenv($name);
            if (false !== $value && ('1' === $value || 'true' === strtolower((string) $value))) {
                return true;
            }
        }

        return false;
    }

    public function __construct(int $mode = self::MODE_NORMAL) {
        ObjectRegistry::reset();
        self::clearLastParseFailure();
        $this->mode = $mode;
        $this->initParsePipeline();
        $this->initCompiler();
        $this->initVmContext();
        $this->loadCoreModules();
    }

    /** PhpParser + PHPCfg traversers; LLVM 9 crashes when inlined in __construct (#1402, #1494). */
    private function initParsePipeline(): void {
        $astTraverser = new NodeTraverser;
        $astTraverser->addVisitor(
            new NodeVisitor\NameResolver
        );
        $astTraverser->addVisitor(new GroupUseStripper());
        $this->abstractEnumMarker = new Ast\AbstractEnumMarker();
        $astTraverser->addVisitor($this->abstractEnumMarker);
        $this->sealedClassAnnotator = new SealedClassAnnotator();
        $astTraverser->addVisitor($this->sealedClassAnnotator);
        $this->parser = new Parser(
            (new ParserFactory)->create(ParserFactory::ONLY_PHP7),
            $astTraverser
        );

        $this->preprocessor = new Traverser;
        $this->preprocessor->addVisitor(new Visitor\Simplifier);
        $this->preprocessor->addVisitor(new Visitor\DeadBlockEliminator);
        $this->postprocessor = new Traverser;
        $this->postprocessor->addVisitor(new Visitor\PhiResolver);
        $this->detector = new NullSafeLivenessDetector;
        $this->assignOpResolver = new Optimizer\AssignOp;

        $this->typeReconstructor = new TypeReconstructor;
    }

    /**
     * M5 vendor prelink compiles curated vendor bundles for cold boot. Some vendor doc-comments can
     * contain junk type fragments that upstream PHPTypes rejects (issue #2751 / #2743).
     *
     * This is intentionally bootstrap-only: keep normal compilation strict.
     */
    private function isBootstrapVendorPrelinkMode(): bool
    {
        $flag = getenv('PHP_COMPILER_VENDOR_PRELINK');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** Compiler instance; split from VMContext for M3 link (#1494). */
    private function initCompiler(): void {
        $this->compiler = new Compiler;
    }

    /** VMContext only; `new VM` deferred to run() (#1494). */
    private function initVmContext(): void {
        $this->vmContext = new VMContext($this);
    }

    private function ensureVm(): void {
        if (null === $this->vm) {
            $this->vm = new VM($this->vmContext);
        }
    }

    public function vm(): VM {
        $this->ensureVm();

        return $this->vm;
    }

    public function __destruct() {
        foreach ($this->modules as $module) {
            $module->shutdown();
        }
    }

    public function setDebug(?string $debugFile = null): void {
        $this->debugFile = $debugFile;
    }

    public function setAotDebugSymbols(bool $enabled = true): void
    {
        if ($enabled) {
            JIT\AotDebugSymbols::enable();
        }
    }

    private function loadCoreModules(): void {
        $this->load(new ext\types\Module);
        $this->load(new ext\standard\Module);
    }

    public function loadJit(): JIT {
        if (is_null($this->jit)) {
            $this->jit = $this->createJit($this->jitContextForLoadJit());
            if (!$this->shouldSkipLoadJitCompileModuleFuncs()) {
                $this->loadJitCompileModuleFuncs($this->jit);
            }
        }
        return $this->jit;
    }

    /** M3 emit smoke: compile main block only; skip eager ext/ JIT (#1983, #2599). */
    private function shouldSkipLoadJitCompileModuleFuncs(): bool
    {
        if (JIT\CompileCache::shouldSkipModuleFuncCompile()) {
            return true;
        }

        return JIT\EmitTuMode::isMinimalRuntime();
    }

    /** Avoid inlining loadJitContext into loadJit (LLVM 9 crash when both are real-lowered #1402). */
    private function jitContextForLoadJit(): JITContext {
        return $this->loadJitContext();
    }

    /** `new JIT` crashes LLVM 9 when lowered inside loadJit (#1402); stub until fixed. */
    private function createJit(JITContext $context): JIT {
        return new JIT($context);
    }

    /** Nested foreach + compileFunc crashes LLVM 9 when lowered inside loadJit (#1402). */
    private function loadJitCompileModuleFuncs(JIT $jit): void {
        foreach ($this->modules as $module) {
            foreach ($module->getFunctions() as $func) {
                $jit->compileFunc($func);
            }
        }
    }

    public function loadJitContext(): JITContext {
        if (is_null($this->jitContext)) {
            $this->jitContext = new JITContext(
                $this,
                $this->mode === self::MODE_NORMAL ? JIT\Builtin::LOAD_TYPE_EMBED : JIT\Builtin::LOAD_TYPE_STANDALONE
            );
            if (!is_null($this->debugFile)) {
                $this->jitContext->setDebugFile($this->debugFile);
            }
            foreach ($this->modules as $module) {
                $this->jitContext->registerModule($module);
            }
        }
        return $this->jitContext;
    }

    public function load(Module $module): void {
        $this->modules[] = $module;
        $module->init($this);
        foreach ($module->getFunctions() as $function) {
            $this->vmContext->declareFunction($function);
        }
    }

    public function preprocessSourceForParse(string $code): array
    {
        $sealedPreprocessor = new SealedClassPreprocessor();
        [$code, $permitsByLine] = $sealedPreprocessor->preprocess($code);
        $this->sealedClassAnnotator->setPermitsByLine($permitsByLine);
        [$code] = (new SourcePreprocessor\PropertyHooks())->process($code);
        $code = SwitchCommaCaseRewriter::rewrite($code);
        $code = GenericArrayTypeSourceRewriter::rewrite($code);
        [$code, $abstractEnumLines] = AbstractEnumSourceRewriter::rewrite($code);
        $this->abstractEnumMarker->setAbstractLines($abstractEnumLines);

        return SourceBareThrowRewriter::rewrite($code);
    }

    public function parse(string $code, string $filename): Script {
        [$code, $bareRethrowLines] = $this->preprocessSourceForParse($code);
        $this->compiler->setBareRethrowLines($bareRethrowLines);
        $code = AsymmetricVisibilityRewriter::rewrite($code);
        $code = PipeOperatorDesugar::desugar($code);
        try {
            $script = $this->parser->parse($code, $filename);
        } finally {
            $this->abstractEnumMarker->clear();
        }
        $this->preprocessor->traverse($script);
        if (!$this->isBootstrapVendorPrelinkMode()) {
            $state = new State($script);
            $this->typeReconstructor->resolve($state);
        }
        $this->postprocessor->traverse($script);
        $this->detector->detect($script);
        return $script;
    }

    /**
     * Log the first CFG-level abort before rethrowing (issue #2642, self-host triage).
     */
    private function emitParseCompileFailureStderr(string $sourcePath, \Throwable $e, ?string $sourceCode = null): void
    {
        $detail = $this->compiler->getCompileAbortDetail();
        $primary = null !== $detail && '' !== $detail ? $detail : $e->getMessage();
        $this->recordLastParseFailure(sprintf('%s: %s', $sourcePath, $primary));
        $line = sprintf("parseAndCompile failure: target=%s: %s\n", $sourcePath, $primary);
        $context = null;
        if (null !== $sourceCode && $e instanceof \PhpParser\Error) {
            $context = $this->formatPhpParserErrorContext($sourceCode, $e);
        }
        if (\defined('STDERR') && \is_resource(STDERR)) {
            fwrite(STDERR, $line);
            if (null !== $context && '' !== $context) {
                fwrite(STDERR, $context);
            }

            return;
        }
        error_log(rtrim($line));
        if (null !== $context && '' !== $context) {
            error_log(rtrim($context));
        }
    }

    private function formatPhpParserErrorContext(string $sourceCode, \PhpParser\Error $e): ?string
    {
        // nikic/php-parser messages commonly end with "on line N". Provide a snippet plus
        // best-effort mapping to the bundled file marker emitted by SourceBundler.
        if (!preg_match('/\\bon line (\\d+)\\b/', $e->getMessage(), $m)) {
            return null;
        }
        $lineNo = (int) $m[1];
        if ($lineNo <= 0) {
            return null;
        }
        $lines = preg_split('/\\r\\n|\\n|\\r/', $sourceCode) ?: [];
        $idx = $lineNo - 1;
        if (!isset($lines[$idx])) {
            return null;
        }

        $marker = null;
        for ($i = $idx; $i >= 0; --$i) {
            if (\PHPCompiler\Web\SourceBundler::isBundleFileMarker($lines[$i])) {
                $marker = trim($lines[$i]);
                break;
            }
        }
        $from = max(0, $idx - 4);
        $to = min(count($lines) - 1, $idx + 4);
        $out = "\n";
        if (null !== $marker) {
            $out .= "  bundle_context: {$marker}\n";
        }
        $out .= "  bundle_snippet:\n";
        for ($i = $from; $i <= $to; ++$i) {
            $prefix = ($i === $idx) ? '>' : ' ';
            $out .= sprintf("  %s %6d | %s\n", $prefix, $i + 1, $lines[$i]);
        }

        return $out;
    }

    public function compile(Script $script): ?Block {
        /** @var mixed $block */
        $block = $this->compiler->compile($script);
        if (!$block instanceof Block) {
            // Self-host AOT can surface unexpected stub returns as null; preserve a stable abort detail.
            $this->compiler->setCompileAbortDetailIfEmpty('Runtime::compile: Compiler::compile returned non-Block');
            $sourceFile = $this->compiler->getDebugLastPhaseInputFile() ?? $script->main->getFile();
            $this->emitParseAndCompileNullDiagnostic($script, $sourceFile);

            return null;
        }

        $this->assignOpResolver->optimize($block);

        return $block;
    }

    /** M3 native emit: compile trivial scripts via compileEmitSmoke (#1937). */
    public function compileEmitSmoke(Script $script): ?Block {
        $block = $this->compiler->compileEmitSmoke($script);
        if (!$block instanceof Block) {
            $this->compiler->setCompileAbortDetailIfEmpty('Runtime::compileEmitSmoke: Compiler::compileEmitSmoke returned non-Block');
            $sourceFile = $this->compiler->getDebugLastPhaseInputFile() ?? $script->main->getFile();
            $this->emitParseAndCompileNullDiagnostic($script, $sourceFile);

            return null;
        }
        $this->assignOpResolver->optimize($block);

        return $block;
    }

    /** Last parse/compile failure text for native vendor invoker (#3037). */
    public static function getLastParseFailure(): ?string
    {
        return self::$lastParseFailure;
    }

    /** Instance shim so M3 emit TU can read {@see getLastParseFailure()} via runtimeSpine (#3037). */
    public function peekLastParseFailure(): ?string
    {
        return self::getLastParseFailure();
    }

    public static function clearLastParseFailure(): void
    {
        self::$lastParseFailure = null;
    }

    /**
     * Record compile-null detail when parse+compile are split (M3 emit TU bridge, #3037).
     */
    public function noteParseCompileNullForScript(?Script $script): void
    {
        $this->recordLastParseFailure($this->formatParseAndCompileNullDetail($script));
    }

    private function recordLastParseFailure(?string $detail): void
    {
        if (null !== $detail && '' !== $detail) {
            self::$lastParseFailure = $detail;
        }
    }

    public function parseAndCompileEmitSmoke(string $code, string $filename): ?Block
    {
        self::clearLastParseFailure();
        $this->compiler->resetCompileAbortDetail();
        $this->compiler->setDebugLastPhaseInputFile($filename);
        try {
            $script = $this->parse($code, $filename);
            $block = $this->compileEmitSmoke($script);
            if (null !== $block) {
                $block->setScriptPath($filename);
            }

            return $block;
        } catch (\Throwable $e) {
            $this->emitParseCompileFailureStderr($filename, $e, $code);
            throw $e;
        }
    }

    public function compileFunc(string $name, CfgFunc $func): Func {
        $compiled = $this->compiler->compileFunc($name, $func);
        $this->assignOpResolver->optimize($compiled->block);
        return $compiled;
    }

    public function jit(?Block $block, ?string $sourceCode = null, ?string $sourcePath = null) {
        $this->jitLoadedFromDiskCache = false;
        $this->jitCompileCacheKey = null;

        if (
            null !== $block
            && is_string($sourceCode)
            && is_string($sourcePath)
            && JIT\CompileCache::isEnabled()
        ) {
            $cacheKey = JIT\CompileCache::computeKey($sourcePath, $sourceCode);
            $this->jitCompileCacheKey = $cacheKey;
            if (JIT\CompileCache::isFresh($cacheKey, $sourcePath, $sourceCode)) {
                $context = $this->loadJitContext();
                if (JIT\CompileCache::tryRestore($context, $block, $cacheKey)) {
                    $this->jitLoadedFromDiskCache = true;
                }
            }
            if (!$this->jitLoadedFromDiskCache) {
                JIT\CompileCache::beginRecording($cacheKey);
            }
        }

        if (!$this->jitLoadedFromDiskCache) {
            $this->jitCompileBlock($block);
        }
        $this->jitEmitInPlace();

        if (
            !$this->jitLoadedFromDiskCache
            && null !== $this->jitCompileCacheKey
            && null !== $this->jitContext
        ) {
            JIT\CompileCache::save($this->jitContext, $this->jitCompileCacheKey);
        }
        JIT\CompileCache::finishRecording();
    }

    /** Lower script block to LLVM IR (issue #1898 bench-compile phases). */
    public function jitCompileBlock(?Block $block): void {
        $this->loadJit()->compile($block);
    }

    /** MCJIT link / engine creation; no-op when already compiled in-process (#153 warm). */
    public function jitEmitInPlace(): void {
        if ($this->jitLoadedFromDiskCache) {
            $this->loadJitContext()->compileInPlaceFromDiskCache();
        } else {
            $this->loadJitContext()->compileInPlace();
        }
    }

    public function standalone(?Block $block, string $outfile, ?string $sourceCode = null, ?string $sourceFilename = null) {
        \PHPCompiler\JIT\Progress::noteFunction('runtime_standalone_begin');
        $context = $this->loadJitContext();
        if (null !== $sourceFilename && '' !== $sourceFilename) {
            $context->setAotSourceFilename($sourceFilename);
        }
        \PHPCompiler\JIT\Progress::noteFunction('runtime_standalone_loadjitcontext_done');
        // Generator bodies use GeneratorHelper resume lowering; script-scope yield still blocked (#3115).
        if (null !== $block && Block::containsGeneratorOpcodesInScriptScope($block)) {
            throw new \LogicException('yield in the main script is not supported in AOT yet (issue #3115).');
        }
        $context->setMain($this->loadJit()->compile($block));
        \PHPCompiler\JIT\Progress::noteFunction('runtime_standalone_compile_done');
        $context->compileToFile($outfile);
        \PHPCompiler\JIT\Progress::noteFunction('runtime_standalone_compiletofile_done');
    }

    public function parseAndCompile(string $code, string $filename): ?Block {
        self::clearLastParseFailure();
        $this->compiler->resetCompileAbortDetail();
        $this->compiler->setDebugLastPhaseInputFile($filename);
        \PHPCompiler\JIT\Progress::notePhase('runtime_parseandcompile_begin');
        \PHPCompiler\JIT\Progress::noteEntry($filename);
        try {
            $script = $this->parse($code, $filename);
            $block = $this->compile($script);
            if (null !== $block) {
                $block->setScriptPath($filename);
            }

            return $block;
        } catch (\Throwable $e) {
            $this->emitParseCompileFailureStderr($filename, $e, $code);
            throw $e;
        }
    }

    public function parseAndCompileFile(string $filename): ?Block {
        self::clearLastParseFailure();
        $this->compiler->resetCompileAbortDetail();
        try {
            $normalized = VM\ScriptStack::normalize($filename);
            if ('' !== $normalized) {
                $filename = $normalized;
            }
            $this->compiler->setDebugLastPhaseInputFile($filename);
            \PHPCompiler\JIT\Progress::notePhase('runtime_parseandcompilefile_begin');
            \PHPCompiler\JIT\Progress::noteEntry($filename);

            \PHPCompiler\JIT\Progress::noteFunction('runtime_parseandcompilefile_read_begin');
            $code = (string) file_get_contents($filename);
            \PHPCompiler\JIT\Progress::noteFunction('runtime_parseandcompilefile_read_done');
            $script = $this->parse($code, $filename);
            \PHPCompiler\JIT\Progress::noteFunction('runtime_parseandcompilefile_parse_done');
            $block = $this->compile($script);
            \PHPCompiler\JIT\Progress::noteFunction('runtime_parseandcompilefile_compile_done');
            if (null !== $block) {
                $block->setScriptPath($filename);
            }

            return $block;
        } catch (\Throwable $e) {
            $this->emitParseCompileFailureStderr($filename, $e, isset($code) ? $code : null);
            throw $e;
        }
    }

    /**
     * Human detail for `parseAndCompile()` / emit-smoke returning null (#2642, #2633).
     *
     * Prefer compile abort detail from the real compiler; otherwise lint the parsed script.
     */
    public function formatParseAndCompileNullDetail(?Script $script): ?string
    {
        $detail = $this->compiler->getCompileAbortDetail();
        if (null !== $detail && '' !== $detail) {
            return $detail;
        }
        if (null === $script) {
            return 'parse returned null';
        }

        $lint = new LintCompiler();
        $prev = $this->compiler;
        $this->compiler = $lint;
        try {
            $this->compile($script);
        } catch (\Throwable $e) {
            // parse/type failure — lint may still have recorded an issue.
        } finally {
            $this->compiler = $prev;
        }

        $issue = $lint->issues[0] ?? null;

        return null !== $issue ? $issue->formatHuman() : null;
    }

    /**
     * `parseAndCompile()` returning null is a common self-host bootstrap failure mode (#2642).
     * Best-effort: re-run compile under the lint compiler and print the first unsupported kind.
     */
    private function emitParseAndCompileNullDiagnostic(Script $script, ?string $sourceFile = null): void
    {
        $detail = $this->formatParseAndCompileNullDetail($script);
        $this->recordLastParseFailure($detail);
        if (!self::isParseDiagEnabled()) {
            return;
        }

        if (null === $detail || '' === $detail) {
            $detail = 'unknown compile failure (no lint issue recorded)';
        }
        if (null === $sourceFile || '' === $sourceFile) {
            $sourceFile = $script->main->getFile();
        }
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $line = sprintf("parseAndCompile: %s: %s\n", $sourceFile, $detail);
        if (\defined('STDERR') && \is_resource(STDERR)) {
            fwrite(STDERR, $line);

            return;
        }
        echo $line;
    }

    /**
     * Refresh JIT sg_* tables from VM / CGI env before each run (issue #642).
     */
    public function syncJitSuperglobals(
        ?string $queryString = null,
        ?string $postBody = null,
        ?string $scriptFilename = null
    ): void {
        Superglobals::populateFromEnvironment(
            $this->vmContext,
            $queryString,
            $postBody,
            $scriptFilename
        );
        if (null !== $this->jitContext) {
            $this->jitContext->refreshSuperglobals();
        }
    }

    public function run(?Block $block) {
        $this->ensureVm();
        Superglobals::setActiveContext($this->vmContext);
        try {
            return $this->vm->run($block);
        } finally {
            ShutdownQueue::run($this->vmContext);
            OutputBuffer::endAllAtShutdown();
            Superglobals::setActiveContext(null);
        }
    }

}
