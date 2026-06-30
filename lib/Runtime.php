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
use PHPCompiler\VM\HashTableRegistry;
use PHPCompiler\JIT\Context as JITContext;
use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPCompiler\Ast\DnfParenTypeRewriter;
use PHPCompiler\Ast\GlobalTypedConstRewriter;
use PHPCompiler\Ast\TypedFunctionStaticRewriter;
use PHPCompiler\Ast\GroupUseStripper;
use PHPCompiler\Ast\MultiBlockNameResolver;
use PHPCompiler\Ast\SealedClassAnnotator;
use PHPCompiler\Ast\SealedClassPreprocessor;
use PHPCompiler\Ast\StaticClassAnnotator;
use PHPCompiler\Ast\StaticClassPreprocessor;
use PHPCompiler\Ast\InOperatorDesugar;
use PHPCompiler\Ast\ExitFunctionDesugar;
use PHPCompiler\Ast\HexFloatLiteralDesugar;
use PHPCompiler\Ast\NewDereferenceableDesugar;
use PHPCompiler\Ast\CloneWithDesugar;
use PHPCompiler\Ast\PipeOperatorDesugar;
use PHPCompiler\EncapsedCoalesceRejector;
use PHPCompiler\Ast\VoidCastDesugar;
use PHPCompiler\Visitor\InOperatorResolver;
use PHPCompiler\Visitor\ExitFunctionResolver;
use PHPCompiler\Visitor\VoidCastResolver;
use PHPCompiler\Web\ServeCompileCache;
use PHPCompiler\Web\Superglobals;
use PHPCompiler\Lint\LintCompiler;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\VM\MemoryAccounting;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\ShutdownQueue;
use PHPCompiler\ext\standard\VmObGzhandler;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Variable;

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
    private StaticClassAnnotator $staticClassAnnotator;
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
        HashTableRegistry::reset();
        ext\standard\ModuleRegistry::reset();
        self::clearLastParseFailure();
        ext\standard\VmIniIntrospection::seedHostIniEnvFromZend();
        $this->mode = $mode;
        $this->initParsePipeline();
        $this->initCompiler();
        $this->initVmContext();
        $this->loadCoreModules();
    }

    /** PhpParser + PHPCfg traversers; LLVM 9 crashes when inlined in __construct (#1402, #1494). */
    private function initParsePipeline(): void {
        $astTraverser = new NodeTraverser;
        $astTraverser->addVisitor(new MultiBlockNameResolver());
        $astTraverser->addVisitor(new Ast\EnumCaseImportRewriter());
        $astTraverser->addVisitor(new Ast\EnumCaseMatchSwitchRewriter());
        $astTraverser->addVisitor(new GroupUseStripper());
        $this->abstractEnumMarker = new Ast\AbstractEnumMarker();
        $astTraverser->addVisitor($this->abstractEnumMarker);
        $this->sealedClassAnnotator = new SealedClassAnnotator();
        $astTraverser->addVisitor($this->sealedClassAnnotator);
        $this->staticClassAnnotator = new StaticClassAnnotator();
        $astTraverser->addVisitor($this->staticClassAnnotator);
        $astTraverser->addVisitor(new Ast\EnumPropertyCompileCheck());
        $astTraverser->addVisitor(new Ast\GeneratorYieldSourceMarker());
        $this->parser = new Parser(
            (new ParserFactory)->create(ParserFactory::ONLY_PHP7),
            $astTraverser
        );

        $this->preprocessor = new Traverser;
        $this->preprocessor->addVisitor(new InOperatorResolver);
        $this->preprocessor->addVisitor(new ExitFunctionResolver);
        $this->preprocessor->addVisitor(new VoidCastResolver);
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
        Web\VendorSpineAutoload::register($this);
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
        $this->load(new ext\spl\Module);
        $this->load(new ext\intl\Module);
        $this->load(new ext\zip\Module);
        $this->load(new ext\libxml\Module);
        $this->load(new ext\dom\Module);
        $this->load(new ext\xml\Module);
        $this->load(new ext\gd\Module);
        $this->load(new ext\iconv\Module);
        $this->load(new ext\gettext\Module);
        $this->load(new ext\mbstring\Module);
        $this->load(new ext\filter\Module);
        $this->load(new ext\calendar\Module);
        $this->load(new ext\session\Module);
        $this->load(new ext\bcmath\Module);
        $this->load(new ext\stats\Module);
        $this->load(new ext\openssl\Module);
        $this->load(new ext\curl\Module);
        $this->load(new ext\hash\Module);
        $this->load(new ext\posix\Module);
        $this->load(new ext\sockets\Module);
        $this->load(new ext\ctype\Module);
        $this->load(new ext\tokenizer\Module);
        $this->load(new ext\random\Module);
        $this->load(new ext\igbinary\Module);
        $this->load(new ext\msgpack\Module);
        $this->load(new ext\zstd\Module);
        $this->load(new ext\lzf\Module);
        $this->load(new ext\bz2\Module);
        $this->load(new ext\sodium\Module);
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

        if (JIT\EmitTuMode::isMinimalRuntime()) {
            return true;
        }

        $loadType = $this->mode === self::MODE_NORMAL
            ? JIT\Builtin::LOAD_TYPE_EMBED
            : JIT\Builtin::LOAD_TYPE_STANDALONE;

        return JIT\LazyBuiltins::isEnabled($loadType);
    }

    /**
     * Lower a registered ext/* function on first reference (issue #94).
     */
    public function ensureJitBuiltinCompiled(string $proxyName): bool
    {
        foreach ($this->jitBuiltinLookupCandidates($proxyName) as $candidate) {
            foreach ($this->modules as $module) {
                foreach ($module->getFunctions() as $func) {
                    if (strtolower($func->getName()) !== $candidate) {
                        continue;
                    }
                    $this->loadJit()->compileFunc($func);

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function jitBuiltinLookupCandidates(string $proxyName): array
    {
        $lc = strtolower($proxyName);
        $candidates = [$lc];
        if (preg_match('/^(.+)\\\\([^\\\\]+)::(.+)$/', $lc, $matches)) {
            $candidates[] = $matches[2].'::'.$matches[3];
        }
        if (str_contains($lc, '\\') && !str_contains($lc, '::')) {
            $candidates[] = substr($lc, strrpos($lc, '\\') + 1);
        }

        return array_values(array_unique($candidates));
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
        ext\standard\ModuleRegistry::registerModule($module);
        foreach ($module->getFunctions() as $function) {
            $this->vmContext->declareFunction($function);
        }
    }

    public function preprocessSourceForParse(string $code, string $filename = 'unknown'): array
    {
        AsymmetricVisibilityRejector::reject($code, $filename);
        CloneWithSyntaxRejector::reject($code, $filename);
        ExitFunctionSyntaxRejector::reject($code, $filename);
        PropertyHookSyntaxRejector::reject($code, $filename);
        $sealedPreprocessor = new SealedClassPreprocessor();
        [$code, $permitsByLine] = $sealedPreprocessor->preprocess($code);
        $this->sealedClassAnnotator->setPermitsByLine($permitsByLine);
        $staticPreprocessor = new StaticClassPreprocessor();
        [$code, $staticLines] = $staticPreprocessor->preprocess($code);
        $this->staticClassAnnotator->setStaticLines($staticLines);
        [$code, $newRegistry] = (new SourcePreprocessor\PropertyHooks())->process($code, $filename);
        if (\PHPCompiler\ext\standard\VmEval::EVAL_FILENAME === $filename) {
            $this->vmContext->propertyHookRegistry = self::mergePropertyHookRegistry(
                $this->vmContext->propertyHookRegistry,
                $newRegistry
            );
        } else {
            $this->vmContext->propertyHookRegistry = $newRegistry;
        }
        CurlyBraceOffsetRejector::reject($code, $filename);
        EncapsedCoalesceRejector::reject($code, $filename);
        ReadonlyMethodModifierRejector::reject($code, $filename);
        ReadonlyFunctionRejector::reject($code, $filename);
        $code = EnumCaseListRewriter::rewrite($code);
        $code = SwitchCommaCaseRewriter::rewrite($code);
        $code = GenericArrayTypeSourceRewriter::rewrite($code);
        [$code, $abstractEnumLines] = AbstractEnumSourceRewriter::rewrite($code);
        $this->abstractEnumMarker->setAbstractLines($abstractEnumLines);

        return SourceBareThrowRewriter::rewrite($code);
    }

    /**
     * eval() compile units append hook metadata; file units replace (#7030, #7031).
     *
     * @param array<string, array<string, array<string, mixed>>> $existing
     * @param array<string, array<string, array<string, mixed>>> $incoming
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function mergePropertyHookRegistry(array $existing, array $incoming): array
    {
        foreach ($incoming as $classLc => $props) {
            if (!isset($existing[$classLc])) {
                $existing[$classLc] = $props;

                continue;
            }
            foreach ($props as $prop => $meta) {
                $existing[$classLc][$prop] = array_merge($existing[$classLc][$prop] ?? [], $meta);
            }
        }

        return $existing;
    }

    /**
     * Full preprocess + parser desugar chain shared by VM, JIT, AOT, lint, and include discovery.
     *
     * Order is SSOT: sealed/static preprocessors, {@see SourcePreprocessor\PropertyHooks} (before
     * {@see CurlyBraceOffsetRejector}), enum/switch/generic rewriters, bare-throw rewrite, then
     * parser desugar passes (#6650, #6654).
     *
     * @return array{0: string, 1: array<int, true>}
     */
    public function prepareSourceForParser(string $code, string $filename = 'unknown'): array
    {
        [$code, $bareRethrowLines] = $this->preprocessSourceForParse($code, $filename);
        $code = $this->rewriteSourceBeforeParser($code);

        return [$code, $bareRethrowLines];
    }

    /**
     * Parse for literal include discovery without type reconstruction (#1416).
     *
     * Resets php-parser NameResolver state so consecutive file parses do not collide on `use` imports.
     */
    public function parseForIncludeDiscovery(string $code, string $filename): Script
    {
        [$code, $bareRethrowLines] = $this->prepareSourceForParser($code, $filename);
        if (method_exists($this->compiler, 'setBareRethrowLines')) {
            $this->compiler->setBareRethrowLines($bareRethrowLines);
        }
        $this->resetParserNameResolverState();
        try {
            $script = $this->parser->parse($code, $filename);
        } finally {
            $this->abstractEnumMarker->clear();
        }
        $this->preprocessor->traverse($script);

        return $script;
    }

    /**
     * Source rewrites applied immediately before php-parser / PHPCfg (issue #3243, #4456).
     *
     * Must run on any path that calls Parser::parse() directly (AOT include discovery, etc.).
     */
    public function rewriteSourceBeforeParser(string $code): string
    {
        $code = GlobalTypedConstRewriter::rewrite($code);
        $code = DnfParenTypeRewriter::rewrite($code);
        $code = AsymmetricVisibilityRewriter::rewrite($code);
        $code = TypedFunctionStaticRewriter::rewrite($code);
        $code = HexFloatLiteralDesugar::desugar($code);
        $code = NewDereferenceableDesugar::desugar($code);
        $code = InOperatorDesugar::desugar($code);
        $code = ExitFunctionDesugar::desugar($code);
        $code = CloneWithDesugar::desugar($code);
        $code = VoidCastDesugar::desugar($code);
        $code = PipeOperatorDesugar::desugar($code);
        return $code;
    }

    public function parse(string $code, string $filename): Script {
        [$code, $bareRethrowLines] = $this->prepareSourceForParser($code, $filename);
        if (method_exists($this->compiler, 'setBareRethrowLines')) {
            $this->compiler->setBareRethrowLines($bareRethrowLines);
        }
        $fileStrictTypes = $this->detectFileStrictTypes($code);
        $this->resetParserNameResolverState();
        try {
            $script = $this->parser->parse($code, $filename);
        } finally {
            $this->abstractEnumMarker->clear();
        }
        $this->preprocessor->traverse($script);
        $vendorPrelink = getenv('PHP_COMPILER_VENDOR_PRELINK');
        if ('1' !== $vendorPrelink && 'true' !== strtolower((string) $vendorPrelink)) {
            $state = new State($script);
            $this->typeReconstructor->resolve($state);
        }
        $this->postprocessor->traverse($script);
        $this->detector->detect($script);
        // `declare(strict_types=1)` is file-scoped and influences call-site scalar type checks.
        // Some parser paths miss the directive when `<?php declare(...)` shares a line (#4411).
        if ($fileStrictTypes) {
            $script->main->strictTypes = true;
        }
        return $script;
    }

    /**
     * Reset php-parser NameResolver aliases before parsing another compilation unit (#1416, #9252).
     *
     * Required for bootstrap-inventory lint sweeps and any path that calls Parser::parse() without
     * going through {@see parse()}.
     */
    public function resetParserNameResolverBeforeParse(): void
    {
        $this->resetParserNameResolverState();
    }

    /** php-parser NameResolver aliases persist across traversals on one PHPCfg Parser (#1416). */
    private function resetParserNameResolverState(): void
    {
        $parserRef = new \ReflectionProperty(\PHPCfg\Parser::class, 'astTraverser');
        $parserRef->setAccessible(true);
        $traverser = $parserRef->getValue($this->parser);
        $visitorsRef = new \ReflectionProperty($traverser, 'visitors');
        $visitorsRef->setAccessible(true);
        foreach ($visitorsRef->getValue($traverser) as $visitor) {
            if ($visitor instanceof \PHPCompiler\Ast\MultiBlockNameResolver) {
                $context = $visitor->getNameContext();
                if ($context instanceof \PHPCompiler\Ast\MultiBlockNameContext) {
                    $context->beginCompilationUnit();
                    continue;
                }
            }
            if ($visitor instanceof \PhpParser\NodeVisitor\NameResolver) {
                $visitor->getNameContext()->startNamespace();
            }
        }
    }

    private function detectFileStrictTypes(string $code): bool
    {
        if (!\function_exists('token_get_all')) {
            return false;
        }
        $tokens = @token_get_all($code);
        if (!\is_array($tokens)) {
            return false;
        }
        $i = 0;
        $n = \count($tokens);
        while ($i < $n) {
            $t = $tokens[$i];
            $id = \is_array($t) ? $t[0] : null;
            if (\in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                ++$i;
                continue;
            }
            break;
        }
        if ($i >= $n) {
            return false;
        }
        $t = $tokens[$i];
        if (!\is_array($t) || T_DECLARE !== $t[0]) {
            return false;
        }
        ++$i;
        while ($i < $n) {
            $t = $tokens[$i];
            $text = \is_array($t) ? (string) $t[1] : (string) $t;
            if ('(' === $text) {
                break;
            }
            ++$i;
        }
        if ($i >= $n) {
            return false;
        }
        ++$i; // after '('
        $level = 1;
        $body = '';
        for (; $i < $n; ++$i) {
            $t = $tokens[$i];
            $text = \is_array($t) ? (string) $t[1] : (string) $t;
            if ('(' === $text) {
                ++$level;
            } elseif (')' === $text) {
                --$level;
                if (0 === $level) {
                    break;
                }
            }
            if ($level > 0) {
                $body .= $text;
            }
        }
        if ('' === $body) {
            return false;
        }

        return (bool) preg_match('/\\bstrict_types\\s*=\\s*1\\b/i', $body);
    }

    /**
     * Log the first CFG-level abort before rethrowing (issue #2642, self-host triage).
     */
    private function emitParseCompileFailureStderr(string $sourcePath, \Throwable $e, ?string $sourceCode = null): void
    {
        $detail = $this->compiler->getCompileAbortDetail();
        $primary = null !== $detail && '' !== $detail ? $detail : $e->getMessage();
        if ($e instanceof CompileFatal) {
            $line = $e->zendStderrLine();
            $this->recordLastParseFailure(sprintf('%s: %s', $e->sourceFile, $primary));
        } else {
            $this->recordLastParseFailure(sprintf('%s: %s', $sourcePath, $primary));
            $line = sprintf("parseAndCompile failure: target=%s: %s\n", $sourcePath, $primary);
        }
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
        $this->compiler->setPropertyHookRegistry($this->vmContext->propertyHookRegistry);
        $this->compiler->setKnownClassReadonly(self::knownClassReadonlyForCompileCheck($this->vmContext->classes));
        $this->compiler->setRuntimeEnumCaseConsts(self::runtimeEnumCaseConstsForCompile($this->vmContext->classes));
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
                $block->setCompileSource($code);
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
            $this->compiler->setCompileSourceCode($code);
            $block = $this->compile($script);
            if (null !== $block) {
                $block->setScriptPath($filename);
                $block->setCompileSource($code);
            }

            return $block;
        } catch (\Throwable $e) {
            if (\PHPCompiler\ext\standard\VmEval::EVAL_FILENAME === $filename) {
                $detail = $this->compiler->getCompileAbortDetail();
                $primary = null !== $detail && '' !== $detail ? $detail : $e->getMessage();
                $this->recordLastParseFailure(sprintf('%s: %s', $filename, $primary));
            } else {
                $this->emitParseCompileFailureStderr($filename, $e, $code);
            }
            throw $e;
        }
    }

    public function parseAndCompileFile(string $filename): ?Block {
        if (ServeCompileCache::isEnabled() && !ServeCompileCache::isLoading()) {
            return ServeCompileCache::getFile($this, $filename);
        }
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
            $this->compiler->setCompileSourceCode($code);
            $block = $this->compile($script);
            \PHPCompiler\JIT\Progress::noteFunction('runtime_parseandcompilefile_compile_done');
            if (null !== $block) {
                $block->setScriptPath($filename);
                $block->setCompileSource($code);
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
        MemoryAccounting::beginRequest();
        Superglobals::setActiveContext($this->vmContext);
        OutputBuffer::setActiveContext($this->vmContext);
        try {
            return $this->vm->run($block);
        } finally {
            ShutdownQueue::run($this->vmContext);
            OutputBuffer::endAllAtShutdown();
            OutputBuffer::setActiveContext(null);
            VmObGzhandler::reset();
            Superglobals::setActiveContext(null);
        }
    }

    /**
     * @param array<string, ClassEntry> $classes
     *
     * @return array<string, array{display: string, readonly: bool, extends: ?string}>
     */
    private static function knownClassReadonlyForCompileCheck(array $classes): array
    {
        $known = [];
        foreach ($classes as $lc => $entry) {
            if (!$entry instanceof ClassEntry) {
                continue;
            }
            if ($entry->isInterface || $entry->isTrait || $entry->isEnum) {
                continue;
            }
            $display = $entry->name;
            if (str_contains($display, '\\')) {
                $parts = explode('\\', ltrim($display, '\\'));
                $display = end($parts) ?: $display;
            }
            $known[$lc] = [
                'display' => $display,
                'readonly' => $entry->readonly,
                'extends' => $entry->parentLc,
            ];
        }

        return $known;
    }

    /**
     * @param array<string, ClassEntry> $classes
     *
     * @return array<string, array<string, Variable>>
     */
    private static function runtimeEnumCaseConstsForCompile(array $classes): array
    {
        $runtime = [];
        foreach ($classes as $lc => $entry) {
            if (!$entry instanceof ClassEntry || !$entry->isEnum || !$entry->isInternal) {
                continue;
            }
            foreach ($entry->constants as $constLc => $value) {
                if (!$value instanceof Variable) {
                    continue;
                }
                $stored = new Variable();
                $stored->copyFrom($value);
                $runtime[$lc][$constLc] = $stored;
            }
        }

        return $runtime;
    }

}
