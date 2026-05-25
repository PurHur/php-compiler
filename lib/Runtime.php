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
use PHPCompiler\JIT\Context as JITContext;
use PHPCompiler\Web\Superglobals;

class Runtime {
    const MODE_NORMAL   = 0b0001;
    const MODE_AOT      = 0b0010;

    public Compiler $compiler;
    public Parser $parser;
    public Traverser $preprocessor;
    public Traverser $postprocessor;
    public CfgLivenessDetector $detector;
    public Optimizer $assignOpResolver;
    public VMContext $vmContext;
    public ?VM $vm = null;
    private ?JITContext $jitContext = null;
    private ?JIT $jit = null;
    public array $modules = [];
    public int $mode;
    public ?string $debugFile = null;

    public TypeReconstructor $typeReconstructor;

    public function __construct(int $mode = self::MODE_NORMAL) {
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

    public function __destruct() {
        foreach ($this->modules as $module) {
            $module->shutdown();
        }
    }

    public function setDebug(?string $debugFile = null): void {
        $this->debugFile = $debugFile;
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

    /** M3 emit smoke: compile main block only; skip eager ext/ JIT (#1983). */
    private function shouldSkipLoadJitCompileModuleFuncs(): bool
    {
        $flag = getenv('PHP_COMPILER_M3_EMIT_MINIMAL');

        return '1' === $flag || 'true' === strtolower((string) $flag);
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

    public function parse(string $code, string $filename): Script {
        $script = $this->parser->parse($code, $filename);
        $this->preprocessor->traverse($script);
        $state = new State($script);
        $this->typeReconstructor->resolve($state);
        $this->postprocessor->traverse($script);
        $this->detector->detect($script);
        return $script;
    }

    public function compile(Script $script): ?Block {
        $block = $this->compiler->compile($script);
        $this->assignOpResolver->optimize($block);
        return $block;
    }

    public function compileFunc(string $name, CfgFunc $func): Func {
        $compiled = $this->compiler->compileFunc($name, $func);
        $this->assignOpResolver->optimize($compiled->block);
        return $compiled;
    }

    public function jit(?Block $block) {
        $this->jitCompileBlock($block);
        $this->jitEmitInPlace();
    }

    /** Lower script block to LLVM IR (issue #1898 bench-compile phases). */
    public function jitCompileBlock(?Block $block): void {
        $this->loadJit()->compile($block);
    }

    /** MCJIT link / engine creation; no-op when already compiled in-process (#153 warm). */
    public function jitEmitInPlace(): void {
        $this->loadJitContext()->compileInPlace();
    }

    public function standalone(?Block $block, string $outfile) {
        $context = $this->loadJitContext();
        $context->setMain($this->loadJit()->compile($block));
        $context->compileToFile($outfile);
    }

    public function parseAndCompile(string $code, string $filename): ?Block {
        $block = $this->compile($this->parse($code, $filename));
        if (null !== $block) {
            $block->setScriptPath($filename);
        }

        return $block;
    }

    public function parseAndCompileFile(string $filename): ?Block {
        $normalized = VM\ScriptStack::normalize($filename);
        if ('' !== $normalized) {
            $filename = $normalized;
        }

        $block = $this->compile($this->parse(file_get_contents($filename), $filename));
        if (null !== $block) {
            $block->setScriptPath($filename);
        }

        return $block;
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
            Superglobals::setActiveContext(null);
        }
    }

}
