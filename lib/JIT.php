<?php

# This file is generated, changes you make will be lost.
# Make your changes in /compiler/lib/JIT.pre instead.

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

require_once __DIR__.'/OpCodeNames.php';

use PHPCfg\Operand;
use PHPCfg\Op;
use PHPTypes\Type;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\IssetHelper;
use PHPCompiler\JIT\SelfHostBuiltinPolicy;
use PHPCompiler\JIT\Variable;

use PHPCompiler\Func as CoreFunc;

use PHPLLVM;

class JIT {

    private static int $functionNumber = 0;
    private static int $blockNumber = 0;

    public int $optimizationLevel = 3;


    private array $stringConstant = [];
    private array $intConstant = [];
    private array $builtIns = [];

    private array $queue = [];

    public Context $context;

    public function __construct(Context $context) {
        $this->context = $context;
    }

    public function compile(Block $block): PHPLLVM\Value {
        $return = $this->compileBlock($block);
        $this->runQueue();
        return $return;
    }

    public function compileFunc(CoreFunc $func): void {
        if ($func instanceof CoreFunc\PHP) {
            $name = $func->getName();
            // Large switch crashes LLVM during JIT (issue #540); VM uses host PHP for this helper.
            if ('opcode_type_name' === $name || str_ends_with($name, '\\opcode_type_name')) {
                return;
            }
            if (
                $this->isSkippedVmHotPathName($name)
                || $this->isSkippedCompilerHotPathName($name)
                || $this->isSkippedWebBootstrapHotPathName($name)
                || $this->isSkippedSelfHostEntryName($name)
                || $this->isSkippedBootstrapInterpreterHotPathName($name)
                || $this->isSkippedIssetHelperHotPathName($name)
            ) {
                $this->compileBlock($func->block, $name);

                return;
            }
            $this->compileBlock($func->block, $name);
            $this->runQueue();
            return;
        } elseif ($func instanceof CoreFunc\JIT) {
            // No need to do anything, already compiled
            return;
        } elseif ($func instanceof CoreFunc\Internal) {
            $name = strtolower($func->getName());
            if (SelfHostBuiltinPolicy::shouldExternalStub($name)) {
                $this->context->functionProxies[$name] = new JIT\Call\ExternalMethod($func->getName());

                return;
            }
            $this->context->functionProxies[$name] = $func;

            return;
        }
        throw new \LogicException("Unknown func type encountered: " . get_class($func));
    }

    private function runQueue(): void {
        while (!empty($this->queue)) {
            $run = array_shift($this->queue);
            $classId = $this->context->scope->classId;
            $className = $this->context->scope->className;
            $this->context->scope = new JIT\Scope();
            $this->context->scope->classId = $classId;
            $this->context->scope->className = $className;
            $this->context->scopeStack = [];
            $this->context->inlineIncludeReturnOperands = [];
            $this->context->coalesceAssignTargets = new \SplObjectStorage();
            $this->compileBlockInternal($run[0], $run[1], null, null, ...$run[2]);
        }
    }

    /**
     * php-cfg dead operands before branchIf run before any successor; skip inside inlined
     * includes so template locals survive layout title-branch partial includes (#784, #764).
     */
    private function shouldFreeDeadVariablesBeforeBranch(): bool
    {
        return 0 === $this->context->inlineIncludeDepth;
    }

    /**
     * ?? on superglobals can disturb inherited include locals; restore before use (#866, #784).
     */
    private function maybeRefreshIncludeBindingsBeforeUse(): void
    {
        if ($this->context->inlineIncludeDepth > 0) {
            JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
        }
    }

    /** Self-host AOT sets PHP_COMPILER_SELFHOST_AOT=1 (#816, #557). */
    private function shouldUseSelfHostJitStubs(): bool
    {
        $flag = getenv('PHP_COMPILER_SELFHOST_AOT');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    private function compileBlock(Block $block, ?string $funcName = null): PHPLLVM\Value {
        $logicalName = $funcName;
        if (!is_null($funcName)) {
            $internalName = $this->llvmInternalName($funcName);
        } else {
            $internalName = "internal_" . (++self::$functionNumber);
        }
        if (str_contains($internalName, 'opcode_type_name')) {
            return $this->compileSkippedOpcodeNameStub($internalName, $block);
        }
        if ($this->isSkippedVmHotPathName($logicalName ?? $internalName)) {
            return $this->compileSkippedVmHotPathStub($internalName, $block, $logicalName ?? $internalName);
        }
        if ($this->isSkippedCompilerHotPathName($logicalName ?? $internalName)
            || $this->isSkippedWebBootstrapHotPathName($logicalName ?? $internalName)
            || $this->isSkippedSelfHostEntryName($logicalName ?? $internalName)
            || $this->isSkippedBootstrapInterpreterHotPathName($logicalName ?? $internalName)
            || $this->isSkippedIssetHelperHotPathName($logicalName ?? $internalName)
        ) {
            return $this->compileSkippedCompilerSplitCfgStub($internalName, $block, $logicalName ?? $internalName);
        }
        $args = [];
        $rawTypes = [];
        $argVars = [];
        if (!is_null($block->func)) {
            $callbackType = $this->cfgFunctionReturnCallbackType($block->func) ?? '__value__';
            if ('__construct' === strtolower($block->func->name)) {
                $callbackType = 'void';
            }
            $returnType = $this->context->getTypeFromString($callbackType);
            $this->context->functionReturnType[strtolower($logicalName ?? $internalName)] = $callbackType;

            if ($this->instanceMethodUsesThis($block)) {
                $rawTypes[] = Type::object();
                $args[] = $this->context->getTypeFromString('__object__*');
            }
            $callbackType .= '(*)(';
            $callbackSep = '';
            foreach ($args as $type) {
                $callbackType .= $callbackSep . $this->context->getStringFromType($type);
                $callbackSep = ', ';
            }
            foreach ($block->func->params as $idx => $param) {
                $rawType = $this->rawTypeFromCfgParam($param);
                $type = $this->llvmTypeForCfgParam($param);
                $callbackType .= $callbackSep . $this->context->getStringFromType($type);
                $callbackSep = ', ';
                $rawTypes[] = $rawType;
                $args[] = $type;
            }
            $callbackType .= ')';
        } else {
            $callbackType = 'void(*)()';
            $returnType = $this->context->getTypeFromString('void');
        }

        $isVarArgs = false;

        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType(
                $returnType,
                $isVarArgs,
                ...$args
            )
        );

        foreach ($args as $idx => $arg) {
            $argVars[] = new Variable($this->context, Variable::getTypeFromType($rawTypes[$idx]), Variable::KIND_VALUE, $func->getParam($idx));
        }

        $lcname = strtolower($logicalName ?? $internalName);
        $this->context->functions[$lcname] = $func;
        if (!is_null($funcName)) {
            $lcname = strtolower($funcName);
            $this->context->activeFunction = $lcname;
            $this->context->functions[$lcname] = $func;
            if ($isVarArgs) {
                $this->context->functionProxies[$lcname] = new JIT\Call\Vararg($func, $funcName, count($args));
            } else {
                $defaultArgs = $this->collectParamDefaults($block);
                $this->context->functionProxies[$lcname] = new JIT\Call\Native($func, $funcName, $args, $defaultArgs);
            }
        }

        $this->queue[] = [$func, $block, $argVars];
        if ($callbackType === 'void(*)()') {
            $this->context->addExport($internalName, $callbackType, $block);
        }
        return $func;
    }

    private function llvmInternalName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?? $name;
    }

    /**
     * Stub out opcode_type_name() — the real implementation is a large switch that crashes LLVM 9 JIT (#540).
     */
    private function compileSkippedOpcodeNameStub(string $internalName, Block $block): PHPLLVM\Value
    {
        $lcname = strtolower($internalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $mangled = $this->llvmInternalName($internalName);
        $func = $this->context->module->addFunction(
            $mangled,
            $this->context->context->functionType(
                $this->context->getTypeFromString('__string__*'),
                false,
                $this->context->getTypeFromString('int64')
            )
        );
        $bb = $func->appendBasicBlock('stub');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue(
            $this->context->builder->load($this->context->constantStringFromString('TYPE_UNKNOWN'))
        );
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;

        return $func;
    }

    private function isSkippedVmHotPathName(string $name): bool
    {
        $lower = strtolower($name);
        // Self-host AOT bundles lib/VM.php for closure lint only; stub the interpreter (#816, #913).
        if (str_contains($lower, '\\vm::')) {
            return true;
        }

        return str_ends_with($lower, '::runframes') || str_ends_with($lower, '::defineclass')
            || str_ends_with($lower, '::getframe');
    }

    /** Stub bundled lib/ interpreter helpers for self-host AOT (#557, #816). */
    private function isSkippedBootstrapInterpreterHotPathName(string $name): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        if ($this->isSkippedSelfHostEntryName($name)) {
            return false;
        }
        $lower = strtolower($name);

        if (str_contains($lower, '\\vm::')
            || str_contains($lower, '\\block::')
            || str_contains($lower, '\\frame::')
            || str_contains($lower, '\\module::')
            || str_contains($lower, '\\runtime::')
            || $this->isSkippedJitResultHotPathName($lower)
        ) {
            return true;
        }
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }

        return str_contains($lower, '\\vm\\')
            || str_contains($lower, '\\vm\\variable::')
            || str_contains($lower, '\\printer::')
            || str_contains($lower, '\\opcode::')
            || str_contains($lower, '\\methodvisibility::')
            || str_contains($lower, '\\nullsafelivenessdetector::')
            || str_contains($lower, '\\moduleabstract::')
            || str_contains($lower, '\\opcodenames::')
            || str_contains($lower, '\\lint\\')
            || str_contains($lower, '\\jit\\')
            || str_contains($lower, '\\func\\jit::')
            || str_contains($lower, '\\func\\internal::')
            || str_contains($lower, '\\jit::');
    }

    /** Skip JIT\\Result FFI bodies (getCallable/getFunc) during self-host native link (#816). */
    private function isSkippedJitResultHotPathName(string $lowerName): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }

        return str_contains($lowerName, '\\jit\\result::');
    }

    /** Stub IssetHelper (superglobalName OperandName walk crashes LLVM 9 during self-host AOT). */
    private function isSkippedIssetHelperHotPathName(string $name): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }

        return str_contains(strtolower($name), '\\jit\\issethelper::');
    }

    private function isSkippedCompilerHotPathName(string $name): bool
    {
        $lower = strtolower($name);

        return str_contains($lower, 'splitcfgblockafterstringkeyedarray')
            || str_contains($lower, 'compilecfgbranch')
            || str_contains($lower, 'compilecfgblock')
            || str_contains($lower, 'compileblock')
            || str_contains($lower, 'compileops')
            || str_contains($lower, 'compileclasslike')
            || str_contains($lower, 'compileclassbody')
            || str_contains($lower, 'compilefunction')
            || str_contains($lower, 'compileglobalconst')
            || str_contains($lower, 'compilestmt')
            || str_contains($lower, 'compileop')
            || str_contains($lower, 'compileswitchasjumpifchain')
            || str_contains($lower, 'compileexpr')
            || str_contains($lower, 'getopcodetype')
            || str_contains($lower, 'compileissetmulti')
            || str_contains($lower, 'compileisset')
            || str_contains($lower, 'compilecoalesce')
            || str_contains($lower, 'compilenullsafe')
            || str_contains($lower, 'compileincludeop')
            || str_contains($lower, 'compileparam')
            || str_contains($lower, 'compileterminal')
            || str_contains($lower, 'compilefunccall')
            || str_contains($lower, 'compilearraydimfetchread')
            || str_contains($lower, 'compilebooltemporary')
            || str_contains($lower, 'compileboolconstant')
            || str_contains($lower, 'compiletypeconstrainedvariable')
            || str_contains($lower, 'compileclassconstfetch')
            || str_contains($lower, 'compileinstanceof')
            || str_contains($lower, 'trycompiledefineasglobalconst')
            || str_contains($lower, 'markcallerlocalsusedbyliteralinclude')
            || str_contains($lower, 'requireoperandslot')
            || str_contains($lower, 'resolvesimplevariablename')
            || str_contains($lower, 'unwrap')
            || str_contains($lower, 'needscfg')
            || str_contains($lower, 'inheritfuncfromparent')
            || str_contains($lower, 'isarraydim')
            || str_contains($lower, 'findcoalesce')
            || str_contains($lower, 'resolvecoalesce')
            || str_contains($lower, 'resolveisset');
    }

    private function isSkippedSelfHostEntryName(string $name): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $lower = strtolower($name);
        // Self-host bundle includes Runtime/VM/Func for closure only; stub non-Compiler bodies (#913).
        if (str_contains($lower, '\\runtime::')
            || str_contains($lower, '\\func\\php::')
            || str_contains($lower, '\\func::')
            || str_contains($lower, '\\frame::')
            || str_contains($lower, '\\block::')
        ) {
            return true;
        }

        return str_ends_with($lower, '\\compiler::compilefunc')
            || str_ends_with($lower, '\\compiler::compile')
            || 'type_pair' === $lower;
    }

    private function isSkippedWebBootstrapHotPathName(string $name): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $lower = strtolower($name);
        return (str_contains($lower, '\\web\\includepathresolver::') && !$this->isIncludePathResolverRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\literalincludediscovery::') && !$this->isLiteralIncludeDiscoveryRealLoweringMethod($lower))
            || str_contains($lower, 'deployroot')
            || str_contains($lower, 'sourcebundler')
            || (str_contains($lower, '\\web\\conststringfolder::') && !$this->isConstStringFolderRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\superglobals::') && !str_ends_with($lower, '::issuperglobalname'));
    }

    /** IncludePathResolver methods with safe LLVM 9 lowering during self-host AOT (#816). */
    private function isIncludePathResolverRealLoweringMethod(string $lower): bool
    {
        return str_ends_with($lower, '::resolve');
    }

    /** LiteralIncludeDiscovery methods with safe LLVM 9 lowering during self-host AOT (#816). */
    private function isLiteralIncludeDiscoveryRealLoweringMethod(string $lower): bool
    {
        return false;
    }

    /** ConstStringFolder methods with safe LLVM 9 lowering during self-host AOT (#816). */
    private function isConstStringFolderRealLoweringMethod(string $lower): bool
    {
        return str_ends_with($lower, '::literalstringvalue')
            || str_ends_with($lower, '::sourcedir');
    }

    private function collectStubFunctionArgTypes(Block $block): array
    {
        $args = [];
        if (null === $block->func) {
            return $args;
        }
        if ($this->instanceMethodUsesThis($block)) {
            $args[] = $this->context->getTypeFromString('__object__*');
        }
        foreach ($block->func->params as $param) {
            $args[] = $this->llvmTypeForCfgParam($param);
        }
        return $args;
    }

    private function llvmTypeForCfgParam(\PHPCfg\Op\Expr\Param $param): PHPLLVM\Type
    {
        $rawType = $this->rawTypeFromCfgParam($param);
        $callback = $this->callbackTypeFromPhptype($rawType);
        if (null !== $callback) {
            return $this->context->getTypeFromString($callback);
        }

        return $this->context->getTypeFromType($rawType);
    }

    /** Stub VM hot-path methods whose opcode switches crash LLVM 9 during self-host AOT (#816). */
    private function compileSkippedVmHotPathStub(string $internalName, Block $block, string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $args = $this->collectStubFunctionArgTypes($block);
        $callbackType = $this->cfgFunctionReturnCallbackType($block->func) ?? 'void';
        $returnType = $this->context->getTypeFromString($callbackType);
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($returnType, false, ...$args)
        );
        $bb = $func->appendBasicBlock('stub');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->emitSelfHostStubReturn($callbackType, $func, VM::SUCCESS);
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = $callbackType;
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            $args,
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /** Stub Compiler::compileCfgBranch() for LLVM 9 self-host AOT (#816). */
    private function compileSkippedCompilerCfgBranchStub(string $internalName, Block $block, string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($objectPtr, false, $objectPtr, $objectPtr, $objectPtr)
        );
        $bb = $func->appendBasicBlock('stub');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objectPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = '__object__*';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $objectPtr, $objectPtr],
            []
        );

        return $func;
    }

    /** Stub Compiler CFG helpers that crash LLVM 9 during self-host AOT (#816). */
    private function compileSkippedCompilerSplitCfgStub(string $internalName, Block $block, string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $args = $this->collectStubFunctionArgTypes($block);
        $callbackType = $this->cfgFunctionReturnCallbackType($block->func) ?? '__object__*';
        $returnType = $this->context->getTypeFromString($callbackType);
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($returnType, false, ...$args)
        );
        $bb = $func->appendBasicBlock('stub');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->emitSelfHostStubReturn($callbackType, $func);
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = $callbackType;
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            $args,
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    public function compileSubBlock(
        PHPLLVM\Value $func,
        Block $block,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        $limit = $block->nOpCodes;
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            --$limit;
        }

        return $this->compileBlockInternal($func, $block, $limit, null, ...$args);
    }

    /**
     * Inline an included compilation unit at a dedicated entry block (issue #568 / MiniWebApp templates).
     */
    public function compileIncludedAtEntry(
        PHPLLVM\Value $func,
        Block $block,
        PHPLLVM\BasicBlock $entryBlock
    ): PHPLLVM\BasicBlock {
        $limit = $block->nOpCodes;
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            --$limit;
        }

        $this->context->inlineIncludeExitBlock = null;
        $exit = $this->compileBlockInternal($func, $block, $limit, $entryBlock);
        if (null !== $this->context->inlineIncludeExitBlock) {
            $exit = $this->context->inlineIncludeExitBlock;
        }

        return $exit;
    }
    
    private function compileBlockInternal(
        PHPLLVM\Value $func,
        Block $block,
        ?int $limit = null,
        ?PHPLLVM\BasicBlock $entryBlock = null,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        if ($this->context->scope->blockStorage->contains($block)) {
            return $this->context->scope->blockStorage[$block];
        }
        if (null !== $block->func && $block->orig === $block->func->cfg) {
            JIT\Progress::noteFunction($block->func->getScopedName());
        }
        if (null !== $entryBlock) {
            $origBasicBlock = $basicBlock = $entryBlock;
        } else {
            self::$blockNumber++;
            $origBasicBlock = $basicBlock = $func->appendBasicBlock('block_' . self::$blockNumber);
        }
        $this->context->scope->blockStorage[$block] = $basicBlock;
        $builder = $this->context->builder;
        $builder->positionAtEnd($basicBlock);
        if ([] !== $args) {
            $this->context->implicitThisArgument = null;
        }
        // Handle hoisted variables
        foreach ($block->orig->hoistedOperands as $operand) {
            if ($this->context->coalesceAssignTargets->contains($operand)) {
                continue;
            }
            $this->context->makeVariableFromOp($func, $basicBlock, $block, $operand);
        }

        $thisParamOffset = 0;
        if ([] !== $args) {
            if ($this->instanceMethodUsesThis($block)) {
                $thisParamOffset = 1;
            }
            foreach ($block->orig->hoistedOperands as $hoisted) {
                if ('this' === JIT\OperandName::resolve($hoisted)) {
                    if (!$this->context->hasVariableOp($hoisted)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $hoisted);
                    }
                    $this->assignOperand($hoisted, $args[0], true);
                    $thisParamOffset = 1;
                    break;
                }
            }
            if (1 === $thisParamOffset) {
                $this->context->implicitThisArgument = $args[0];
            } else {
                $this->context->implicitThisArgument = null;
            }
            // Only the CFG entry block receives LLVM arguments; branch blocks share the same func (#210).
            if (null !== $block->func && $block->orig === $block->func->cfg) {
                foreach ($block->func->params as $idx => $param) {
                    $argIdx = $thisParamOffset + $idx;
                    if ($argIdx >= count($args)) {
                        break;
                    }
                    if (!$this->context->hasVariableOp($param->result)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $param->result);
                    }
                    $this->assignOperand($param->result, $args[$argIdx], true);
                }
            }
        }

        for ($i = 0, $length = null !== $limit ? $limit : count($block->opCodes); $i < $length; $i++) {
            $op = $block->opCodes[$i];
            switch ($op->type) {
                case OpCode::TYPE_ARG_RECV:
                    $this->assignOperand($block->getOperand($op->arg1), $args[$op->arg2 + $thisParamOffset]);
                    break;
                case OpCode::TYPE_ASSIGN:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg3));
                    $destOp = $block->getOperand($op->arg1);
                    $forceCoalesce = $this->context->coalesceAssignTargets->contains($destOp);
                    $forceAssign = $forceCoalesce
                        || $this->assignOperandsUsedByLiteralInclude($block, $op);
                    $this->assignOperand($block->getOperand($op->arg2), $value, $forceAssign);
                    $this->assignOperand($destOp, $value, $forceAssign);
                    break;  
                case OpCode::TYPE_ASSIGN_REF:
                    $destName = JIT\OperandName::resolve($block->getOperand($op->arg1));
                    $srcName = JIT\OperandName::resolve($block->getOperand($op->arg2));
                    if (null === $destName || null === $srcName) {
                        throw new \LogicException('Reference assignment requires named variables');
                    }
                    $this->context->refAliasNames[$destName] = $this->context->resolveRefAliasName($srcName);
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL:
                    if (!isset($block->constants[$op->arg2])) {
                        throw new \LogicException('Global name must be a compile-time constant');
                    }
                    $globalName = $block->constants[$op->arg2]->toString();
                    $this->context->setVariableOp(
                        $block->getOperand($op->arg1),
                        $this->ensureJitGlobal($globalName)
                    );
                    break;  
                case OpCode::TYPE_ARRAY_DIM_FETCH:
                case OpCode::TYPE_ARRAY_DIM_FETCH_WRITE:
                    $forWrite = OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $op->type;
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $resultOp = $block->getOperand($op->arg1);
                    if (null === $op->arg3) {
                        if (Variable::TYPE_STRING === $value->type) {
                            throw new \LogicException('[] is only supported for arrays');
                        }
                        $this->context->setVariableOp(
                            $resultOp,
                            JIT\HashTableHelper::reserveAppendSlot($this->context, $value)
                        );
                        break;
                    }
                    $dimOp = $block->getOperand($op->arg3);
                    $dim = $this->context->getVariableFromOp($dimOp);
                    $containerOp = $block->getOperand($op->arg2);
                    $containerUserType = $containerOp->type->userType ?? '';
                    if (
                        $value->type === Variable::TYPE_OBJECT
                        && 'splobjectstorage' === strtolower($containerUserType)
                    ) {
                        $ht = $this->context->type->object->splBackingHashtable($value);
                        $keyStr = JIT\HashTableHelper::objectPointerAsStringKey($this->context, $dim);
                        $htVal = $this->context->helper->loadValue($ht);
                        $keyVal = $this->context->helper->loadValue($keyStr);
                        if ($forWrite) {
                            $fetched = JIT\HashTableHelper::writableStringKeyValueBox(
                                $this->context,
                                $htVal,
                                $keyVal
                            );
                            $this->context->setVariableOp($resultOp, $fetched);
                        } else {
                            $fetched = JIT\HashTableHelper::readStringKeyToValueBox(
                                $this->context,
                                $htVal,
                                $keyVal
                            );
                            $this->assignOperand($resultOp, $fetched);
                        }
                        break;
                    }
                    if ($value->type === Variable::TYPE_STRING) {
                        $charPtr = JIT\StringOffsetHelper::dimFetch(
                            $this->context,
                            $value->value,
                            $dim
                        );
                        if ($forWrite) {
                            $this->context->makeVariableFromValueOp($charPtr, $resultOp);
                        } else {
                            $str = JIT\StringOffsetHelper::readAsString($this->context, $charPtr);
                            $this->context->makeVariableFromValueOp($str, $resultOp);
                        }
                        break;
                    }
                    if ($value->type === Variable::TYPE_HASHTABLE) {
                        $fetched = $value->dimFetch($dim, $resultOp->type, $forWrite);
                        if ($forWrite) {
                            $this->context->setVariableOp($resultOp, $fetched);
                        } else {
                            $this->assignOperand($resultOp, $fetched);
                        }
                        break;
                    }
                    if ($value->type & Variable::IS_NATIVE_ARRAY && $this->context->analyzer->needsBoundsCheck($value, $dimOp)) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__nativearray__boundscheck'),
                            $dim->value,
                            $this->context->constantFromInteger($value->nextFreeElement)
                        );
                    }
                    $this->assignOperand(
                        $resultOp,
                        $value->dimFetch($dim, $resultOp->type, $forWrite)
                    );
                    break;
                case OpCode::TYPE_INIT_ARRAY:
                    $result = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    JIT\HashTableHelper::initArray($this->context, $result);
                    if (null !== $op->arg2) {
                        $element = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                        $key = null !== $op->arg3
                            ? $this->context->getVariableFromOp($block->getOperand($op->arg3))
                            : null;
                        JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
                    }
                    break;
                case OpCode::TYPE_ADD_ARRAY_ELEMENT:
                    $result = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    $element = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $key = null !== $op->arg3
                        ? $this->context->getVariableFromOp($block->getOperand($op->arg3))
                        : null;
                    JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
                    break;
                case OpCode::TYPE_TYPE_ASSERT:
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        $this->context->getVariableFromOp($block->getOperand($op->arg2))
                    );
                    break;
                case OpCode::TYPE_EMPTY:
                    $from = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $truthy = (new ext\standard\boolval())->call($this->context, $from);
                    $this->assignOperandValue(
                        $block->getOperand($op->arg1),
                        $this->context->builder->not($truthy)
                    );
                    break;
                case OpCode::TYPE_ISSET:
                    $containerOp = $block->getOperand($op->arg2);
                    $dimOp = null !== $op->arg3 ? $block->getOperand($op->arg3) : null;
                    $container = $this->context->getVariableFromOp($containerOp);
                    $dim = null !== $dimOp ? $this->context->getVariableFromOp($dimOp) : null;
                    $issetResult = IssetHelper::compile(
                        $this->context,
                        $container,
                        $dim,
                        $dimOp,
                        $containerOp
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $issetResult);
                    break;
                case OpCode::TYPE_ITER_RESET:
                    $arrayOp = $block->getOperand($op->arg1);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    JIT\IteratorHelper::compileReset(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp)
                    );
                    break;
                case OpCode::TYPE_ITER_VALID:
                    $arrayOp = $block->getOperand($op->arg2);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    $valid = JIT\IteratorHelper::compileValid(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp)
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $valid);
                    break;
                case OpCode::TYPE_ITER_KEY:
                    $arrayOp = $block->getOperand($op->arg2);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    $key = JIT\IteratorHelper::compileKey(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp)
                    );
                    $this->assignOperand($block->getOperand($op->arg1), $key);
                    break;
                case OpCode::TYPE_ITER_VALUE:
                    if ($op->arg3) {
                        throw new \LogicException('foreach by-reference is not implemented');
                    }
                    $arrayOp = $block->getOperand($op->arg2);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    $value = JIT\IteratorHelper::compileValue(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp)
                    );
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;
                case OpCode::TYPE_SCRIPT_MAGIC:
                    if (OpCode::SCRIPT_MAGIC_LINE === (int) $op->arg3) {
                        $line = null !== $op->arg2 ? (int) $op->arg2 : 1;
                        if ($line < 1) {
                            $line = 1;
                        }
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            JIT\Variable::fromConstantInt($this->context, $line)
                        );
                    } else {
                        $magicStr = JIT\ScriptMagic::stringForBlock($block, (int) $op->arg3);
                        $lit = new Operand\Literal($magicStr);
                        $lit->type = \PHPTypes\Type::string();
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            JIT\Variable::fromLiteral($this->context, $lit)
                        );
                    }
                    break;
                case OpCode::TYPE_INCLUDE:
                    if ($this->context->inlineIncludeDepth > 0) {
                        JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                    }
                    JIT\IncludeHelper::compileLiteral(
                        $this,
                        $func,
                        $block,
                        $op,
                        null !== $op->arg2 ? $block->getOperand($op->arg2) : null
                    );
                    break;
                case OpCode::TYPE_CLONE:
                    // Stub: real clone not implemented yet; assign null so JIT/AOT can proceed.
                    $nullVar = new JIT\Variable(
                        $this->context,
                        Variable::TYPE_NULL,
                        Variable::KIND_VALUE,
                        $this->context->getTypeFromString('__value__*')->constNull()
                    );
                    $nullVar->isNullConstant = true;
                    $this->assignOperand($block->getOperand($op->arg1), $nullVar);
                    break;
                case OpCode::TYPE_BOOLEAN_NOT:
                    $from = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    if ($from->type === Variable::TYPE_NATIVE_BOOL) {
                        $value = $this->context->helper->loadValue($from);
                    } else {
                        $value = $this->context->castToBool($this->context->helper->loadValue($from));
                    }
                    $__right = $value->typeOf()->constInt(1, false);
                            
                        

                        

                        

                        $result = $this->context->builder->bitwiseXor($value, $__right);
    

                    $this->assignOperandValue($block->getOperand($op->arg1), $result);
                    break;
                case OpCode::TYPE_CONCAT:
                    if (null === $op->arg2 || null === $op->arg3) {
                        break;
                    }
                    if (!$this->context->hasVariableOp($block->getOperand($op->arg1))) {
                        // don't bother with constant operations
                        break;
                    }
                    $result = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    $left = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $right = $this->context->getVariableFromOp($block->getOperand($op->arg3));
                    $this->context->type->string->concat($result, $left, $right);
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    break;
                case OpCode::TYPE_CONST_FETCH:
                    $value = null;
                    if (!is_null($op->arg3)) {
                        // try NS constant fetch
                        $value = $this->context->constantFetch($block->getOperand($op->arg3));
                    }
                    if (is_null($value)) {
                        $value = $this->context->constantFetch($block->getOperand($op->arg2));
                    }
                    if (is_null($value)) {
                        $name = $block->getOperand($op->arg2);
                        $label = $name instanceof Operand\Literal ? (string) $name->value : get_class($name);
                        if (null !== $op->arg3) {
                            $ns = $block->getOperand($op->arg3);
                            if ($ns instanceof Operand\Literal) {
                                $label = (string) $ns->value.'\\'.$label;
                            }
                        }
                        throw new \RuntimeException('Unknown constant fetch: '.$label);
                    }
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;
                case OpCode::TYPE_CLASS_CONST_FETCH:
                    $classOp = $block->getOperand($op->arg2);
                    $nameOp = $block->getOperand($op->arg3);
                    assert($nameOp instanceof Operand\Literal);
                    if ('class' === strtolower($nameOp->value)) {
                        $className = $this->resolveClassNameForPseudoConst($block, $classOp);
                        $lit = new Operand\Literal($className);
                        $lit->type = Type::string();
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            JIT\Variable::fromLiteral($this->context, $lit)
                        );
                        break;
                    }
                    if ('native_type_map' === strtolower($nameOp->value) || 'type_map' === strtolower($nameOp->value)) {
                        $classLabel = $classOp instanceof Operand\Literal
                            ? strtolower($classOp->value)
                            : '';
                        if (str_contains($classLabel, 'variable')) {
                            $mapVar = $this->jitVariableArrayClassConstant($nameOp->value);
                            if (null !== $mapVar) {
                                $this->assignOperand($block->getOperand($op->arg1), $mapVar);
                                break;
                            }
                        }
                    }
                    $classId = $this->context->type->object->resolveClassId($classOp);
                    $value = $this->context->type->object->classConstFetch($classId, $nameOp->value);
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;
                case OpCode::TYPE_INSTANCEOF:
                    $classOp = $block->getOperand($op->arg3);
                    assert($classOp instanceof Operand\Literal);
                    $expr = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $result = $this->context->type->object->emitInstanceOf($expr, $classOp->value);
                    $this->assignOperand($block->getOperand($op->arg1), $result);
                    break;
                case OpCode::TYPE_STATIC_PROPERTY_FETCH:
                    // Recorded at compile time; JIT static property fetch is not implemented yet.
                    break;
                case OpCode::TYPE_UNSET:
                    // Recorded at compile time; JIT unset is not implemented yet.
                    break;
                case OpCode::TYPE_CAST_BOOL:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $this->assignOperand($block->getOperand($op->arg1), $value->castTo(Variable::TYPE_NATIVE_BOOL));
                    break;
                case OpCode::TYPE_CAST_INT:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    if (Variable::TYPE_VALUE === $value->type) {
                        $ptr = Variable::KIND_VARIABLE === $value->kind
                            ? $value->value
                            : $this->context->helper->loadValue($value);
                        $long = $this->context->builder->call(
                            $this->context->lookupFunction('__value__readLong'),
                            $ptr
                        );
                        $this->assignOperandValue($block->getOperand($op->arg1), $long);
                    } else {
                        $long = (new ext\standard\intval())->call($this->context, $value);
                        $this->assignOperandValue($block->getOperand($op->arg1), $long);
                    }
                    break;
                case OpCode::TYPE_CAST_STRING:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        JIT\JitNativeString::coerce($this->context, $value)
                    );
                    break;
                case OpCode::TYPE_ECHO:
                case OpCode::TYPE_PRINT:
                    if ($this->context->inlineIncludeDepth > 0) {
                        JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                    }
                    JIT\Builtin\PendingHeaders::emitFlushForStandalone($this->context);
                    $argOffset = $op->type === OpCode::TYPE_ECHO ? $op->arg1 : $op->arg2;
                    $arg = $this->context->getVariableFromOp($block->getOperand($argOffset));
                    if (Variable::KIND_VARIABLE === $arg->kind) {
                        $slotType = $this->context->getStringFromType($arg->value->typeOf());
                        if ('__value__' === $slotType) {
                            JIT\ValueEchoHelper::echo(
                                $this->context,
                                JIT\JitValueBox::pointer($this->context, $arg->value)
                            );
                            break;
                        }
                        if ('__string__' === $slotType && Variable::TYPE_STRING !== $arg->type) {
                            $arg = new Variable(
                                $this->context,
                                Variable::TYPE_STRING,
                                Variable::KIND_VARIABLE,
                                $arg->value
                            );
                        }
                    }
                    switch ($arg->type) {
                        case Variable::TYPE_VALUE:
                            $echoSlot = JIT\JitValueBox::alloc($this->context);
                            JIT\JitValueBox::copyFromPointer(
                                $this->context,
                                $echoSlot,
                                JIT\JitValueBox::valuePtrFromVariable($this->context, $arg)
                            );
                            JIT\ValueEchoHelper::echo(
                                $this->context,
                                JIT\JitValueBox::pointer($this->context, $echoSlot)
                            );
                            break;
                        case Variable::TYPE_STRING:
                            if ($arg->kind === Variable::KIND_VALUE
                                && 'i8*' === $this->context->getStringFromType($arg->value->typeOf())
                            ) {
                                $byte = $this->context->builder->load($arg->value);
                                $fmt = $this->context->builder->pointerCast(
                                    $this->context->constantFromString('%c'),
                                    $this->context->getTypeFromString('char*')
                                );
                                $this->context->builder->call(
                                    $this->context->lookupFunction('printf'),
                                    $fmt,
                                    $byte
                                );
                                break;
                            }
                            $argValue = $this->context->helper->loadValue($arg);
                            $fmt = $this->context->builder->pointerCast(
                        $this->context->constantFromString("%.*s"),
                        $this->context->getTypeFromString('char*')
                    );
    $offset = $this->context->structFieldMap[$argValue->typeOf()->getElementType()->getName()]['length'];
                    $__str__length = $this->context->builder->load(
                        $this->context->builder->structGep($argValue, $offset)
                    );
    $offset = $this->context->structFieldMap[$argValue->typeOf()->getElementType()->getName()]['value'];
                    $__str__value = $this->context->builder->structGep($argValue, $offset);
    $this->context->builder->call(
                    $this->context->lookupFunction('printf') , 
                    $fmt
                    , $__str__length
                    , $__str__value
                    
                );
    
                            break;
                        case Variable::TYPE_NATIVE_LONG:
                            $argValue = $this->context->helper->loadValue($arg);
                            $fmt = $this->context->builder->pointerCast(
                        $this->context->constantFromString("%lld"),
                        $this->context->getTypeFromString('char*')
                    );
    $this->context->builder->call(
                    $this->context->lookupFunction('printf') , 
                    $fmt
                    , $argValue
                    
                );
    
                            break;
                        case Variable::TYPE_NATIVE_DOUBLE:
                            $argValue = $this->context->helper->loadValue($arg);
                            $fmt = $this->context->builder->pointerCast(
                        $this->context->constantFromString("%.14G"),
                        $this->context->getTypeFromString('char*')
                    );
    $this->context->builder->call(
                    $this->context->lookupFunction('printf') , 
                    $fmt
                    , $argValue
                    
                );
    
                            break;
                        case Variable::TYPE_NATIVE_BOOL:
                            $boolVal = $this->context->helper->loadValue($arg);
                            $charPtr = $this->context->getTypeFromString('char*');
                            $trueBlock = JIT\BasicBlockHelper::append($this->context, 'echo_bool_true');
                            $doneBlock = JIT\BasicBlockHelper::append($this->context, 'echo_bool_done');
                            $this->context->builder->branchIf($boolVal, $trueBlock, $doneBlock);
                            $this->context->builder->positionAtEnd($trueBlock);
                            $this->context->builder->call(
                                $this->context->lookupFunction('printf'),
                                $this->context->builder->pointerCast(
                                    $this->context->constantFromString('1'),
                                    $charPtr
                                )
                            );
                            $this->context->builder->branch($doneBlock);
                            $this->context->builder->positionAtEnd($doneBlock);
                            break;

                        case Variable::TYPE_HASHTABLE:
                            JIT\ValueEchoHelper::echoLiteral($this->context, 'Array');
                            break;
                        case Variable::TYPE_OBJECT:
                            JIT\ValueEchoHelper::echoLiteral($this->context, 'Object');
                            break;

                        default:
                            if (0 !== ($arg->type & Variable::IS_NATIVE_ARRAY)) {
                                JIT\ValueEchoHelper::echoLiteral($this->context, 'Array');
                                break;
                            }
                            if (Variable::KIND_VARIABLE === $arg->kind
                                && '__value__' === $this->context->getStringFromType($arg->value->typeOf())
                            ) {
                                JIT\ValueEchoHelper::echo(
                                    $this->context,
                                    JIT\JitValueBox::pointer($this->context, $arg->value)
                                );
                                break;
                            }
                            if (Variable::KIND_VALUE === $arg->kind
                                && '__value__*' === $this->context->getStringFromType($arg->value->typeOf())
                            ) {
                                JIT\ValueEchoHelper::echo($this->context, $arg->value);
                                break;
                            }
                            throw new \LogicException("Echo for type $arg->type not implemented");
                    }
                    if ($op->type === OpCode::TYPE_PRINT) {
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            new Variable($this->context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $this->context->constantFromInteger(1))
                        );
                    }
                    break;
                case OpCode::TYPE_EXIT:
                    if (null === $op->arg2) {
                        $i32 = $this->context->getTypeFromString('int32');
                        $this->context->builder->call(
                            $this->context->lookupFunction('exit'),
                            $i32->constInt(0, false)
                        );
                        break;
                    }
                    $exitArg = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    JIT\Builtin\ScriptExit::emit($this->context, $exitArg);
                    break;
                case OpCode::TYPE_POW:
                    $pow = new \PHPCompiler\ext\standard\pow();
                    $powResult = $pow->call(
                        $this->context,
                        $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                        $this->context->getVariableFromOp($block->getOperand($op->arg3))
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $powResult);
                    break;
                case OpCode::TYPE_MUL:
                case OpCode::TYPE_PLUS:
                case OpCode::TYPE_MINUS:
                case OpCode::TYPE_DIV:
                case OpCode::TYPE_MODULO:
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                case OpCode::TYPE_SHIFT_LEFT:
                case OpCode::TYPE_SHIFT_RIGHT:
                case OpCode::TYPE_GREATER_OR_EQUAL:
                case OpCode::TYPE_SMALLER_OR_EQUAL:
                case OpCode::TYPE_GREATER:
                case OpCode::TYPE_SMALLER:
                case OpCode::TYPE_IDENTICAL:
                case OpCode::TYPE_NOT_IDENTICAL:
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $this->assignOperand(
                        $this->operandAt($block, $op->arg1, opcode_type_name($op->type).' result'),
                        $this->compileBinaryOp(
                            $op,
                            $this->context->getVariableFromOp($this->operandAt($block, $op->arg2, opcode_type_name($op->type).' left')),
                            $this->context->getVariableFromOp($this->operandAt($block, $op->arg3, opcode_type_name($op->type).' right'))
                        )
                    );
                    break;
                case OpCode::TYPE_EQUAL:
                case OpCode::TYPE_NOT_EQUAL:
                case OpCode::TYPE_SPACESHIP:
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $this->assignOperand(
                        $this->operandAt($block, $op->arg1, opcode_type_name($op->type).' result'),
                        $this->compileBinaryOp(
                            $op,
                            $this->context->getVariableFromOp($this->operandAt($block, $op->arg2, opcode_type_name($op->type).' left')),
                            $this->context->getVariableFromOp($this->operandAt($block, $op->arg3, opcode_type_name($op->type).' right'))
                        )
                    );
                    break;
                case OpCode::TYPE_UNARY_MINUS:
                case OpCode::TYPE_BITWISE_NOT:
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        $this->context->helper->unaryOp(
                            $op,
                            $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                        )
                    );
                    break;
                case OpCode::TYPE_CASE:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $switchVar = $this->context->getVariableFromOp($this->operandAt($block, $op->arg1, 'switch value'));
                    $caseVar = $this->context->getVariableFromOp($this->operandAt($block, $op->arg2, 'switch case'));
                    $equalOp = new OpCode(OpCode::TYPE_EQUAL);
                    $matchVar = $this->context->helper->binaryOp($equalOp, $switchVar, $caseVar);
                    $match = $this->context->castToBool(
                        $this->context->helper->loadValue($matchVar)
                    );
                    $this->compileBlockInternal($func, $op->block1, null, null, ...$args);
                    $caseEntry = $this->context->scope->blockStorage[$op->block1];
                    $nextBb = JIT\BasicBlockHelper::append($this->context, 'switch_next_case');
                    $builder->positionAtEnd($branchBlock);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $branchBlock, $block);
                    }
                    $builder->branchIf($match, $caseEntry, $nextBb);
                    $builder->positionAtEnd($nextBb);
                    break;
                case OpCode::TYPE_JUMP:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $this->compileBlockInternal($func, $op->block1, null, null, ...$args);
                    $targetEntry = $this->context->scope->blockStorage[$op->block1];
                    if ($this->context->inlineIncludeDepth > 0) {
                        // Use the merge block itself (not getInsertBlock — callee may be cached) (#846, #784).
                        $this->context->inlineIncludeExitBlock = $targetEntry;
                    }
                    $builder->positionAtEnd($branchBlock);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $branchBlock, $block);
                    }
                    $builder->branch($targetEntry);
                    return $origBasicBlock;
                case OpCode::TYPE_COALESCE:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $coalesceResult = $block->getOperand($op->arg1);
                    $this->context->coalesceAssignTargets[$coalesceResult] = true;
                    $condition = $this->context->castToBool(
                        $this->context->helper->loadValue($this->context->getVariableFromOp($block->getOperand($op->arg2)))
                    );
                    // Branch from the block that defined $condition (e.g. sg_sk_done after $_SERVER['key']).
                    // Repositioning to $branchBlock caused invalid LLVM when ?? left uses multi-block reads (#866).
                    $coalesceTestBlock = $builder->getInsertBlock();
                    $leftTail = JIT\CoalesceHelper::compileBranch($this, $func, $op->block1);
                    $rightTail = JIT\CoalesceHelper::compileBranch($this, $func, $op->block2);
                    $leftEntry = $this->context->scope->blockStorage[$op->block1];
                    $rightEntry = $this->context->scope->blockStorage[$op->block2];
                    $builder->positionAtEnd($coalesceTestBlock);
                    // Do not free php-cfg "dead" operands here; ?? temps are used on branch/merge blocks (#99).
                    $builder->branchIf($condition, $leftEntry, $rightEntry);
                    if (null !== $op->block3) {
                        $mergeBb = JIT\BasicBlockHelper::append($this->context, 'coalesce_merge');
                        $builder->positionAtEnd($leftTail);
                        if (null === $leftTail->getTerminator()) {
                            $builder->branch($mergeBb);
                        }
                        $builder->positionAtEnd($rightTail);
                        if (null === $rightTail->getTerminator()) {
                            $builder->branch($mergeBb);
                        }
                        $builder->positionAtEnd($mergeBb);
                        if ($this->context->inlineIncludeDepth > 0) {
                            JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                        }
                        $merged = $this->compileBlockInternal($func, $op->block3, null, $mergeBb, ...$args);
                        unset($this->context->coalesceAssignTargets[$coalesceResult]);
                        if ($this->context->inlineIncludeDepth > 0) {
                            // Do not set inlineIncludeExitBlock to the ?? merge block (#866, #784).
                            break;
                        }

                        return $merged;
                    }
                    unset($this->context->coalesceAssignTargets[$coalesceResult]);
                    if ($this->context->inlineIncludeDepth > 0) {
                        // Two-branch ?? without merge: continue in the including TU (#866).
                        break;
                    }

                    return $origBasicBlock;
                case OpCode::TYPE_NULLSAFE:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $receiver = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $valuePtr = JIT\Variable::KIND_VARIABLE === $receiver->kind
                        ? $receiver->value
                        : $this->context->helper->loadValue($receiver);
                    $typeByte = $this->context->builder->load(
                        $this->context->builder->structGep(
                            $valuePtr,
                            $this->context->structFieldMap['__value__']['type']
                        )
                    );
                    $i8 = $this->context->getTypeFromString('int8');
                    $isNull = $this->context->builder->icmp(
                        \PHPLLVM\Builder::INT_EQ,
                        $typeByte,
                        $i8->constInt(JIT\Variable::TYPE_NULL, false)
                    );
                    $nullBb = JIT\NullsafeHelper::compileBranch($this, $func, $op->block1);
                    $fetchBb = JIT\NullsafeHelper::compileBranch($this, $func, $op->block2);
                    $builder->positionAtEnd($branchBlock);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $branchBlock, $block);
                    }
                    $builder->branchIf($isNull, $nullBb, $fetchBb);
                    if (null !== $op->block3) {
                        $mergeBb = JIT\BasicBlockHelper::append($this->context, 'nullsafe_merge');
                        $builder->positionAtEnd($nullBb);
                        $builder->branch($mergeBb);
                        $builder->positionAtEnd($fetchBb);
                        $builder->branch($mergeBb);
                        $builder->positionAtEnd($mergeBb);

                        return $this->compileBlockInternal($func, $op->block3, null, $mergeBb, ...$args);
                    }

                    return $origBasicBlock;
                case OpCode::TYPE_JUMPIF:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $condition = $this->context->castToBool(
                        $this->context->helper->loadValue(
                            $this->context->getVariableFromOp($this->operandAt($block, $op->arg1, 'branch condition'))
                        )
                    );
                    // If-branch JUMP may compile a shared merge RETURN_VOID before the else/elseif arm
                    // runs; do not let inlineIncludeExitBlock leak across arms (#784, #846, #764).
                    $savedIncludeExit = null;
                    $exitAfterIfBranch = null;
                    if ($this->context->inlineIncludeDepth > 0) {
                        $savedIncludeExit = $this->context->inlineIncludeExitBlock;
                        $this->context->inlineIncludeExitBlock = null;
                    }
                    $this->compileBlockInternal($func, $op->block1, null, null, ...$args);
                    if ($this->context->inlineIncludeDepth > 0) {
                        $exitAfterIfBranch = $this->context->inlineIncludeExitBlock;
                        $this->context->inlineIncludeExitBlock = null;
                    }
                    $this->compileBlockInternal($func, $op->block2, null, null, ...$args);
                    if ($this->context->inlineIncludeDepth > 0) {
                        $this->context->inlineIncludeExitBlock = $exitAfterIfBranch
                            ?? $this->context->inlineIncludeExitBlock
                            ?? $savedIncludeExit;
                    }
                    $ifEntry = $this->context->scope->blockStorage[$op->block1];
                    $elseEntry = $this->context->scope->blockStorage[$op->block2];
                    $builder->positionAtEnd($branchBlock);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $branchBlock, $block);
                    }
                    $builder->branchIf($condition, $ifEntry, $elseEntry);
                    return $origBasicBlock;
                case OpCode::TYPE_TRY:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $tryBb = $this->compileBlockInternal($func, $op->block1, null, null, ...$args);
                    $builder->positionAtEnd($branchBlock);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $branchBlock, $block);
                    }
                    $builder->branch($tryBb);
                    return $origBasicBlock;
                case OpCode::TYPE_CATCH:
                case OpCode::TYPE_FINALLY:
                    if (null !== $op->block1) {
                        $this->compileBlockInternal($func, $op->block1, null, null, ...$args);
                    }
                    return $origBasicBlock;
                case OpCode::TYPE_THROW:
                    $throwBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($throwBlock);
                    $this->context->freeDeadVariables($func, $throwBlock, $block);
                    $this->context->builder->call($this->context->lookupFunction('abort'));
                    $this->context->llvm->lib->LLVMBuildUnreachable($this->context->builder->builder);
                    return $origBasicBlock;
                case OpCode::TYPE_RETURN_VOID:
                    $returnBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($returnBlock);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $returnBlock, $block);
                    }
                    if (0 === $this->context->inlineIncludeDepth) {
                        if (
                            !$this->isVoidLlvmFunction($func)
                            && null !== $block->func
                            && 'void' !== ($expectedReturn = $this->cfgFunctionReturnCallbackType($block->func))
                        ) {
                            $this->context->builder->returnValue(
                                $this->defaultLlvmReturnValueForCallbackType($expectedReturn, $func)
                            );
                        } else {
                            $this->context->builder->returnVoid();
                        }
                    } else {
                        $this->context->inlineIncludeExitBlock = $returnBlock;
                    }

                    return $this->context->inlineIncludeDepth > 0
                        ? $returnBlock
                        : $origBasicBlock;
                case OpCode::TYPE_RETURN:
                    $return = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    $returnBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($returnBlock);
                    if ($this->context->inlineIncludeDepth > 0) {
                        if ([] !== $this->context->inlineIncludeReturnOperands) {
                            $holderOp = $this->context->inlineIncludeReturnOperands[
                                array_key_last($this->context->inlineIncludeReturnOperands)
                            ];
                            $return->addref();
                            $this->assignOperand($holderOp, $return, true);
                        }
                        $this->context->inlineIncludeExitBlock = $returnBlock;

                        return $returnBlock;
                    }
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $returnBlock, $block);
                    }
                    if ($this->isVoidLlvmFunction($func)) {
                        $this->context->builder->returnVoid();
                    } else {
                        $return->addref();
                        $retval = $this->context->helper->loadValue($return);
                        $expected = $this->cfgFunctionReturnCallbackType($block->func);
                        if (null === $expected && null !== $this->context->activeFunction) {
                            $expected = $this->context->functionReturnType[strtolower($this->context->activeFunction)] ?? null;
                        }
                        $retval = $this->coerceReturnValue($return, $retval, $expected);
                        $this->context->builder->returnValue($retval);
                    }
    
                    return $origBasicBlock;
                case OpCode::TYPE_FUNCDEF:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    $this->compileBlock($op->block1, $nameOp->value);
                    break;
                case OpCode::TYPE_FUNCCALL_INIT:
                    $nameOp = $block->getOperand($op->arg1);
                    if (!$nameOp instanceof Operand\Literal) {
                        if ($this->shouldUseSelfHostJitStubs()) {
                            $this->context->scope->toCall = null;
                            $this->context->scope->args = [];
                            break;
                        }
                        throw new \LogicException("Variable function calls not yet supported");
                    }
                    $lcname = strtolower($nameOp->value);
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy($lcname);
                    $this->context->scope->args = [];
                    break;
                case OpCode::TYPE_STATICCALL_INIT:
                    $classOp = $block->getOperand($op->arg1);
                    $nameOp = $block->getOperand($op->arg2);
                    assert($nameOp instanceof Operand\Literal);
                    if (!$classOp instanceof Operand\Literal) {
                        throw new \LogicException('Static call class must be a literal');
                    }
                    $proxyName = strtolower($classOp->value).'::'.strtolower($nameOp->value);
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                    $this->context->scope->args = [];
                    break;
                case OpCode::TYPE_ARG_SEND:
                    if ($this->context->inlineIncludeDepth > 0) {
                        JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                    }
                    $this->context->scope->args[] = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                    if (is_null($this->context->scope->toCall)) {
                        // short circuit
                        break;
                    }
                    $callArgs = $this->prependImplicitThisForStaticConstruct(
                        $block,
                        $this->context->scope->toCall,
                        $this->context->scope->args
                    );
                    $this->context->scope->toCall->call($this->context, ...$callArgs);
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
                    $callArgs = $this->prependImplicitThisForStaticConstruct(
                        $block,
                        $this->context->scope->toCall,
                        $this->context->scope->args
                    );
                    $result = $this->context->scope->toCall->call($this->context, ...$callArgs);
                    $this->assignOperandValue($block->getOperand($op->arg1), $result);
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL_CONST:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    if (!isset($block->constants[$op->arg2])) {
                        if ($this->shouldUseSelfHostJitStubs()) {
                            break;
                        }
                        throw new \LogicException('Global constant value must be a compile-time constant');
                    }
                    if (!$this->context->runtime->vmContext->defineConstant(
                        $nameOp->value,
                        $block->constants[$op->arg2]
                    )) {
                        throw new \LogicException("Cannot redefine constant {$nameOp->value}");
                    }
                    break;
                case OpCode::TYPE_DECLARE_CLASS:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    $this->context->pushScope();
                    $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                    $this->context->scope->className = strtolower($nameOp->value);
                    $this->compileClass($op->block1, $this->context->scope->classId);
                    $this->context->popScope();
                    break;
                case OpCode::TYPE_NEW:
                    $classOp = $block->getOperand($op->arg2);
                    if ($classOp instanceof Operand\Literal && 0 === strcasecmp($classOp->value, 'SplObjectStorage')) {
                        $classId = $this->context->type->object->lookup('SplObjectStorage');
                        $obj = new Variable(
                            $this->context,
                            Variable::TYPE_OBJECT,
                            Variable::KIND_VALUE,
                            $this->context->type->object->allocate($classId)
                        );
                        $ht = $this->context->type->object->splBackingHashtable($obj);
                        $this->assignOperand($block->getOperand($op->arg1), $ht, true);
                        $this->context->scope->toCall = null;
                        $this->context->scope->args = [];
                    } else {
                        $class = $this->context->type->object->lookupOperand($classOp);
                        $obj = new Variable(
                            $this->context,
                            Variable::TYPE_OBJECT,
                            Variable::KIND_VALUE,
                            $this->context->type->object->allocate($class)
                        );
                        $resultOp = $block->getOperand($op->arg1);
                        $this->assignOperand($resultOp, $obj, true);
                        if ($this->context->type->object->hasConstructor($class)) {
                            $className = $classOp instanceof Operand\Literal
                                ? $classOp->value
                                : $this->context->scope->className;
                            $proxyName = strtolower($className).'::'.'__construct';
                            $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                            $this->context->scope->args = [$this->context->getVariableFromOp($resultOp)];
                        } else {
                            $this->context->scope->toCall = null;
                            $this->context->scope->args = [];
                        }
                    }
                    break;
                case OpCode::TYPE_METHODCALL_INIT:
                    $receiverOp = $block->getOperand($op->arg1);
                    $nameOp = $block->getOperand($op->arg2);
                    assert($nameOp instanceof Operand\Literal);
                    assert($receiverOp->type->type === Type::TYPE_OBJECT);
                    $className = $receiverOp->type->userType
                        ?? ($this->context->scope->className !== '' ? $this->context->scope->className : 'object');
                    $declaringClassLc = strtolower($className);
                    $methodLc = strtolower($nameOp->value);
                    $declaringClassId = $this->context->type->object->lookup($className);
                    $visFlags = $this->context->type->object->methodVisibility($declaringClassId, $methodLc);
                    $callerClassLc = null;
                    if (null !== $block->func?->class) {
                        $callerClassLc = strtolower($block->func->class->value);
                    } elseif ($this->context->scope->className !== '') {
                        $callerClassLc = $this->context->scope->className;
                    }
                    MethodVisibility::assertCallable(
                        $visFlags,
                        $callerClassLc,
                        $declaringClassLc,
                        $className,
                        $nameOp->value
                    );
                    $proxyName = $declaringClassLc.'::'.$methodLc;
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                    $this->context->scope->args = [$this->context->getVariableFromOp($receiverOp)];
                    break;
                case OpCode::TYPE_PROPERTY_FETCH:
                    $result = $block->getOperand($op->arg1);
                    $obj = $block->getOperand($op->arg2);
                    $name = $block->getOperand($op->arg3);
                    assert($name instanceof Operand\Literal);
                    assert($obj->type->type === Type::TYPE_OBJECT);
                    $declaringClass = $obj->type->userType;
                    if (null === $declaringClass && null !== $block->func?->class) {
                        $declaringClass = $block->func->class->value;
                    }
                    if (null === $declaringClass || '' === $declaringClass) {
                        $declaringClass = $this->context->scope->className !== ''
                            ? $this->context->scope->className
                            : 'object';
                    }
                    $this->context->scope->variables[$result] = $this->context->type->object->propertyFetch(
                        $this->loadPropertyFetchReceiver($obj),
                        $declaringClass,
                        $name->value
                    );
                    break;
                default:
                    throw new \LogicException("Unknown JIT opcode: ". opcode_type_name($op->type));
            }
        }

        $tail = $builder->getInsertBlock();
        if (
            0 === $this->context->inlineIncludeDepth
            && $this->isVoidLlvmFunction($func)
            && !$block->syntheticCfgBranch
            && null !== $block->func
            && null !== $tail
            && null === $tail->getTerminator()
        ) {
            $builder->positionAtEnd($tail);
            $this->context->freeDeadVariables($func, $tail, $block);
            $this->context->builder->returnVoid();
        }

        return $builder->getInsertBlock();
    }

    private function coerceReturnValue(Variable $return, PHPLLVM\Value $retval, ?string $expected): PHPLLVM\Value
    {
        if ('__value__*' === $expected) {
            if (Variable::TYPE_VALUE === $return->type) {
                return JIT\JitValueBox::valuePtrFromVariable($this->context, $return);
            }
            if (Variable::TYPE_NULL === $return->type) {
                return $this->context->getTypeFromString('__value__*')->constNull();
            }
            if (Variable::TYPE_STRING === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $owned = $this->context->builder->call(
                    $this->context->lookupFunction('__string__separate'),
                    $retval
                );
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $owned
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
            }

            return $this->context->getTypeFromString('__value__*')->constNull();
        }
        if ('__value__' === $expected) {
            if (Variable::TYPE_VALUE === $return->type) {
                if (Variable::KIND_VARIABLE === $return->kind) {
                    return $this->context->builder->load($return->value);
                }
                if ('__value__*' === $this->context->getStringFromType($retval->typeOf())) {
                    return $this->context->builder->load($retval);
                }

                return $retval;
            }
            if (Variable::TYPE_NULL === $return->type) {
                return $this->loadNullValueStruct();
            }
            if (Variable::TYPE_STRING === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $owned = $this->context->builder->call(
                    $this->context->lookupFunction('__string__separate'),
                    $retval
                );
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $owned
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_OBJECT === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeObject'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_HASHTABLE === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeHashtable'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_NATIVE_LONG === $return->type || Variable::TYPE_NATIVE_BOOL === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $long = Variable::TYPE_NATIVE_BOOL === $return->type
                    ? $this->context->builder->zExt($retval, $this->context->getTypeFromString('int64'))
                    : $retval;
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeLong'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $long
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_NATIVE_DOUBLE === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeDouble'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return $this->context->builder->load($slot);
            }

            return $this->loadNullValueStruct();
        }
        if (null === $expected || Variable::TYPE_VALUE !== $return->type) {
            if ('__string__*' === $expected && Variable::TYPE_VALUE === $return->type) {
                return $this->context->builder->call(
                    $this->context->lookupFunction('__value__readString'),
                    JIT\JitValueBox::valuePtrFromVariable($this->context, $return)
                );
            }

            return $retval;
        }
        if ('__string__*' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readString'),
                JIT\JitValueBox::valuePtrFromVariable($this->context, $return)
            );
        }
        $valuePtr = Variable::KIND_VARIABLE === $return->kind
            ? JIT\JitValueBox::pointer($this->context, $return->value)
            : JIT\BasicBlockHelper::entryAlloca(
                $this->context,
                $this->context->getTypeFromString('__value__')
            );
        if (Variable::KIND_VALUE === $return->kind) {
            $this->context->builder->store($retval, $valuePtr);
        }
        if ('long long' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $valuePtr
            );
        }
        if ('bool' === $expected) {
            return $this->context->builder->truncOrBitCast(
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__readLong'),
                    $valuePtr
                ),
                $this->context->getTypeFromString('int1')
            );
        }
        if ('__object__*' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                $valuePtr
            );
        }
        if ('__hashtable__*' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readHashtable'),
                $valuePtr
            );
        }

        return $retval;
    }

    private function operandAt(Block $block, ?int $slot, string $context): Operand
    {
        if (null === $slot) {
            throw new \LogicException('Missing operand slot for '.$context);
        }

        return $block->getOperand($slot);
    }

    private function isVoidCfgFunction(Block $block): bool
    {
        return 'void' === $this->cfgFunctionReturnCallbackType($block->func);
    }

    private function isVoidLlvmFunction(PHPLLVM\Value $func): bool
    {
        $fnType = $func->typeOf();
        if (!$fnType instanceof \PHPLLVM\Type\Function_) {
            return false;
        }

        return \PHPLLVM\Type::KIND_VOID === $fnType->getReturnType()->getKind();
    }

    private function defaultLlvmReturnValue(PHPLLVM\Value $func): PHPLLVM\Value
    {
        if (null !== $this->context->activeFunction) {
            $expected = $this->context->functionReturnType[$this->context->activeFunction] ?? null;
            if (null !== $expected) {
                return $this->defaultLlvmReturnValueForCallbackType($expected, $func);
            }
        }
        $fnType = $func->typeOf();
        if (!$fnType instanceof \PHPLLVM\Type\Function_) {
            return $this->context->constantFromInteger(0);
        }
        $llvmReturn = $this->context->getStringFromType($fnType->getReturnType());
        if ('unknown' === $llvmReturn && \PHPLLVM\Type::KIND_STRUCT === $fnType->getReturnType()->getKind()) {
            $llvmReturn = '__value__';
        }

        return $this->defaultLlvmReturnValueForCallbackType($llvmReturn, $func);
    }

    private function emitSelfHostStubReturn(string $callbackType, PHPLLVM\Value $func, ?int $longReturn = null): void
    {
        if ('void' === $callbackType) {
            $this->context->builder->returnVoid();
            return;
        }
        $this->context->builder->returnValue(
            $this->defaultLlvmReturnValueForCallbackType($callbackType, $func, $longReturn)
        );
    }

    private function defaultLlvmReturnValueForCallbackType(
        string $callbackType,
        PHPLLVM\Value $func,
        ?int $longReturn = null
    ): PHPLLVM\Value {
        switch ($callbackType) {
            case 'long long':
            case 'int64':
                return $this->context->getTypeFromString('int64')->constInt($longReturn ?? 0, false);
            case 'bool':
            case 'int1':
                return $this->context->getTypeFromString('bool')->constInt(0, false);
            case '__string__*':
                return $this->context->getTypeFromString('__string__*')->constNull();
            case '__object__*':
                return $this->context->getTypeFromString('__object__*')->constNull();
            case '__hashtable__*':
                return $this->context->getTypeFromString('__hashtable__*')->constNull();
            case '__value__*':
                return $this->context->getTypeFromString('__value__*')->constNull();
            case '__value__':
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull'),
                    JIT\JitValueBox::pointer($this->context, $slot)
                );
                return $this->context->builder->load($slot);
            default:
                $fnType = $func->typeOf();
                if ($fnType instanceof \PHPLLVM\Type\Function_) {
                    $returnType = $fnType->getReturnType();
                    if ($this->isValueStructLlvmType($returnType)) {
                        return $this->loadNullValueStruct();
                    }
                    if (\PHPLLVM\Type::KIND_POINTER === $returnType->getKind()) {
                        return $returnType->constNull();
                    }
                    if (\PHPLLVM\Type::KIND_INTEGER === $returnType->getKind()) {
                        return $returnType->constInt(0, false);
                    }
                }
                return $this->context->constantFromInteger(0);
        }
    }

    private function loadNullValueStruct(): PHPLLVM\Value
    {
        $slot = JIT\JitValueBox::alloc($this->context);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            JIT\JitValueBox::pointer($this->context, $slot)
        );

        return $this->context->builder->load($slot);
    }

    private function isValueStructLlvmType(PHPLLVM\Type $type): bool
    {
        return $type->toString() === $this->context->getTypeFromString('__value__')->toString();
    }

    private function assignOperandsUsedByLiteralInclude(Block $block, OpCode $op): bool
    {
        if ([] === $block->literalIncludePaths) {
            return false;
        }
        foreach ($block->literalIncludePaths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $code = file_get_contents($path);
            if (false === $code || '' === $code) {
                continue;
            }
            foreach ([$op->arg1, $op->arg2] as $slotIdx) {
                $name = JIT\OperandName::resolve($block->getOperand($slotIdx));
                if (null === $name || '' === $name) {
                    continue;
                }
                if (preg_match('/\\$'.preg_quote($name, '/').'\\b/', $code)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function rawTypeFromCfgParam(\PHPCfg\Op\Expr\Param $param): Type
    {
        $declared = null;
        if ($param->declaredType instanceof Op\Type\Literal) {
            $declared = Type::fromDecl($param->declaredType->name);
        } elseif ($param->declaredType instanceof Op\Type\Reference && null !== $param->declaredType->type) {
            $declared = Type::fromTypeDecl($param->declaredType->type);
        } elseif (null !== $param->declaredType) {
            try {
                $declared = Type::fromTypeDecl($param->declaredType);
            } catch (\LogicException) {
                $declared = null;
            }
        }
        if (null !== $param->result->type && Type::TYPE_NULL !== $param->result->type->type) {
            return $param->result->type;
        }
        if (null !== $declared) {
            return $declared;
        }
        if (null !== $param->result->type) {
            return $param->result->type;
        }

        return Type::mixed();
    }

    private function rawTypeFromCfgReturn(?\PHPCfg\Op\Type $returnType): ?Type
    {
        if (null === $returnType) {
            return null;
        }
        if ($returnType instanceof Op\Type\Literal) {
            return Type::fromDecl($returnType->name);
        }
        if ($returnType instanceof Op\Type\Reference && null !== $returnType->type) {
            return Type::fromTypeDecl($returnType->type);
        }
        try {
            return Type::fromTypeDecl($returnType);
        } catch (\LogicException) {
            return null;
        }
    }

    private function typeIncludesNull(Type $type): bool
    {
        if (Type::TYPE_NULL === $type->type) {
            return true;
        }
        if (Type::TYPE_UNION === $type->type) {
            foreach ($type->subTypes ?? [] as $sub) {
                if ($this->typeIncludesNull($sub)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function callbackTypeFromPhptype(Type $type): ?string
    {
        $allowsNull = $this->typeIncludesNull($type);
        $type = $this->context->unwrapNullableUnionType($type);
        switch ($type->type) {
            case Type::TYPE_LONG:
                $callback = 'long long';
                break;
            case Type::TYPE_BOOLEAN:
                $callback = 'bool';
                break;
            case Type::TYPE_STRING:
                $callback = '__string__*';
                break;
            case Type::TYPE_OBJECT:
                $callback = '__object__*';
                break;
            case Type::TYPE_ARRAY:
                $callback = '__hashtable__*';
                break;
            case Type::TYPE_NULL:
                $callback = '__value__';
                break;
            default:
                $callback = null;
                break;
        }
        if ($allowsNull && null !== $callback && '__value__' !== $callback) {
            return '__value__*';
        }

        return $callback;
    }

    /**
     * LLVM return type tag for a CFG function (must match compileBlock() signature lowering).
     */
    private function cfgFunctionReturnCallbackType(?\PHPCfg\Func $cfgFunc): ?string
    {
        if (null === $cfgFunc) {
            return null;
        }
        if ('__construct' === strtolower($cfgFunc->name)) {
            return 'void';
        }
        if ($cfgFunc->returnType instanceof Op\Type\Void_) {
            return 'void';
        }
        if ($cfgFunc->returnType instanceof Op\Type\Nullable) {
            $rawReturn = $this->rawTypeFromCfgReturn($cfgFunc->returnType->subtype);
            if (null !== $rawReturn) {
                $callback = $this->callbackTypeFromPhptype($rawReturn);
                if (null !== $callback) {
                    return $callback;
                }
            }
        }
        $rawReturn = $this->rawTypeFromCfgReturn($cfgFunc->returnType);
        if (null !== $rawReturn) {
            $callback = $this->callbackTypeFromPhptype($rawReturn);
            if (null !== $callback) {
                return $callback;
            }
        }
        if ($cfgFunc->returnType instanceof Op\Type\Literal) {
            switch ($cfgFunc->returnType->name) {
                case 'void':
                    return 'void';
                case 'int':
                    return 'long long';
                case 'string':
                    return '__string__*';
                case 'bool':
                    return 'bool';
                case 'object':
                    return '__object__*';
                case 'array':
                    return '__hashtable__*';
                default:
                    return '__value__';
            }
        }

        return '__value__';
    }

    /** Class const / property default lowering only; values live in $block->constants (self-host bundle). */
    private function isSelfHostClassBodyEpilogueOpcode(int $type): bool
    {
        return OpCode::TYPE_UNARY_MINUS === $type
            || OpCode::TYPE_PLUS === $type
            || OpCode::TYPE_MUL === $type
            || OpCode::TYPE_BITWISE_OR === $type
            || OpCode::TYPE_BITWISE_AND === $type
            || OpCode::TYPE_BITWISE_XOR === $type
            || OpCode::TYPE_SHIFT_LEFT === $type
            || OpCode::TYPE_SHIFT_RIGHT === $type;
    }

    private function compileClass(?Block $block, int $classId) {
        if ($block === null) {
            return;
        }
        foreach ($block->opCodes as $op) {
            switch ($op->type) {
                case OpCode::TYPE_DECLARE_PROPERTY:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    $className = $this->context->scope->className ?? '';
                    $declaredJitType = Variable::getTypeFromType($block->getOperand($op->arg3)->type);
                    if (Variable::TYPE_HASHTABLE === $declaredJitType || Variable::TYPE_STRING === $declaredJitType) {
                        $jitType = $declaredJitType;
                    } else {
                        $jitType = $this->context->type->object->externalPropertyJitType(
                            $className,
                            $name->value
                        );
                    }
                    $this->context->type->object->defineProperty($classId, $name->value, $jitType);
                    if (null !== $op->arg2 && isset($block->constants[$op->arg2])) {
                        $this->context->type->object->definePropertyDefault(
                            $classId,
                            $name->value,
                            $block->constants[$op->arg2]
                        );
                    }
                    break;
                case OpCode::TYPE_CONST_FETCH:
                case OpCode::TYPE_CLASS_CONST_FETCH:
                case OpCode::TYPE_INIT_ARRAY:
                    // Default property values are initialized in __object__ allocation.
                    break;
                case OpCode::TYPE_DECLARE_METHOD:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    $methodLc = strtolower($name->value);
                    $visFlags = \PHPCfg\Func::FLAG_PUBLIC;
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $visFlags = MethodVisibility::mask($block->constants[$op->arg3]->toInt());
                    }
                    $this->context->type->object->defineMethodVisibility(
                        $this->context->scope->classId,
                        $methodLc,
                        $visFlags
                    );
                    $methodBlock = $op->block1;
                    $className = null !== $methodBlock && null !== $methodBlock->func?->class
                        ? strtolower($methodBlock->func->class->value)
                        : $this->context->scope->className;
                    $funcName = $className.'::'.$methodLc;
                    if (null !== $methodBlock) {
                        if ('__construct' === $methodLc) {
                            $this->context->type->object->markHasConstructor($this->context->scope->classId);
                        }
                        $this->compileBlock($methodBlock, $funcName);
                    }
                    break;
                case OpCode::TYPE_DECLARE_CLASS_CONST:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    if (!isset($block->constants[$op->arg2])) {
                        if ($this->shouldUseSelfHostJitStubs()) {
                            break;
                        }
                        throw new \LogicException('Class constant value must be a compile-time constant');
                    }
                    $this->context->type->object->defineClassConst(
                        $classId,
                        $name->value,
                        $block->constants[$op->arg2]
                    );
                    break;
                default:
                    if ($this->shouldUseSelfHostJitStubs()) {
                        break;
                    }
                    throw new \LogicException('Other class body types are not jittable for now');
            }
            
        }
    }

    public function assignIncludeResult(Operand $result): void
    {
        if ([] !== $this->context->inlineIncludeReturnOperands) {
            $holderOp = $this->context->inlineIncludeReturnOperands[
                array_key_last($this->context->inlineIncludeReturnOperands)
            ];
            $this->assignOperand($result, $this->context->getVariableFromOp($holderOp), true);

            return;
        }
        $this->assignOperand(
            $result,
            new Variable(
                $this->context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $this->context->constantFromInteger(1)
            )
        );
    }

    public function assignOperandForced(Operand $result, Variable $value): void
    {
        $this->assignOperand($result, $value, true);
    }

    private function assignOperand(Operand $result, Variable $value, bool $force = false): void {
        if (
            !$force
            && empty($result->usages)
            && !$this->context->scope->variables->contains($result)
        ) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            // it's a kind!
            $this->context->makeVariableFromValueOp($this->context->helper->loadValue($value), $result);
            return;
        }
        $result = $this->context->getVariableFromOp($result);
        if ($result === $value) {
            return;
        }
        if (null !== $result->objectPropertySlot) {
            if (null === $result->objectPropertyType) {
                throw new \LogicException('objectPropertySlot requires objectPropertyType');
            }
            $this->context->type->object->propertyStore(
                $result->objectPropertySlot,
                $value,
                $result->objectPropertyType
            );

            return;
        }
        if ($result->kind === Variable::KIND_VALUE && $result->type === Variable::TYPE_STRING) {
            JIT\StringOffsetHelper::dimAssign($this->context, $result->value, $value);

            return;
        }
        if ($result->kind !== Variable::KIND_VARIABLE) {
            throw new \LogicException("Cannot assign to a value");
        }
        if ($value->type === $result->type) {
            if (!$result->includeBinding) {
                $result->free();
            }
            if ($value->type & Variable::IS_NATIVE_ARRAY || Variable::TYPE_HASHTABLE === $value->type) {
                $result->nextFreeElement = $value->nextFreeElement;
            }
            if (Variable::TYPE_VALUE === $value->type) {
                $destLlvm = $result->value->typeOf();
                $destTy = $this->context->getStringFromType($destLlvm);
                if ('__value__' === $destTy || '__value__*' === $destTy) {
                    $destPointsAtStruct = '__value__' === $destTy;
                    if (
                        '__value__*' === $destTy
                        && \PHPLLVM\Type::KIND_POINTER === $destLlvm->getKind()
                        && '__value__' === $this->context->getStringFromType($destLlvm->getElementType())
                    ) {
                        $destPointsAtStruct = true;
                    }
                    if ($destPointsAtStruct) {
                        JIT\JitValueBox::copyFromPointer(
                            $this->context,
                            $result->value,
                            $this->valueBoxPointer($value)
                        );
                    } else {
                        $this->context->builder->store(
                            $this->valueBoxPointer($value),
                            $result->value
                        );
                    }
                    $this->copyObjectPropertyBacking($result, $value);
                    if (null === $result->objectPropertySlot) {
                        $result->addref();
                    }
                    $this->copyValueBoxJitFlags($result, $value);

                    return;
                }
            }
            $toStore = $this->context->helper->loadValue($value);
            $this->context->builder->store(
                $toStore,
                $result->value
            );
            $this->copyObjectPropertyBacking($result, $value);
            if (null === $result->objectPropertySlot) {
                $result->addref();
            }
            $this->copyValueBoxJitFlags($result, $value);

            return;
        } elseif ($result->type === Variable::TYPE_VALUE) {
            // wrap
            $valueRef = $result->value;
            $valueFrom = $value->value;
            switch ($value->type) {
                case Variable::TYPE_NULL:
                    $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull') , 
                    $valueRef
                    
                );
                    $result->isNullConstant = $value->isNullConstant;
    
                    return;
                case Variable::TYPE_NATIVE_LONG:
                    if (null !== $result->writableHt && null !== $result->writableObjectKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setObjectKeyLong'),
                            $result->writableHt,
                            $result->writableObjectKey,
                            $valueFrom
                        );

                        return;
                    }
                    if (null !== $result->writableHt && null !== $result->writableIndex) {
                        JIT\HashTableHelper::setAtIndex(
                            $this->context,
                            $result->writableHt,
                            $result->writableIndex,
                            $value
                        );

                        return;
                    }
                    $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeLong') , 
                    $valueRef
                    , $valueFrom
                    
                );
    
                    return;
                case Variable::TYPE_NATIVE_DOUBLE:
                    $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeDouble') , 
                    $valueRef
                    , $valueFrom
                    
                );
    
                    return;
                case Variable::TYPE_NATIVE_BOOL:
                    JIT\JitValueBox::writeBool(
                        $this->context,
                        $valueRef,
                        $this->context->helper->loadValue($value)
                    );

                    return;
                case Variable::TYPE_STRING:
                    $str = $this->context->helper->loadValue($value);
                    $owned = $this->context->builder->call(
                        $this->context->lookupFunction('__string__separate'),
                        $str
                    );
                    if (null !== $result->writableHt && null !== $result->writableIndex) {
                        JIT\HashTableHelper::setAtIndex(
                            $this->context,
                            $result->writableHt,
                            $result->writableIndex,
                            $value
                        );

                        return;
                    }
                    if (null !== $result->writableHt && null !== $result->writableStringKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setStringKeyString'),
                            $result->writableHt,
                            $result->writableStringKey,
                            $owned
                        );

                        return;
                    }
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeString'),
                        $valueRef,
                        $owned
                    );

                    return;
                case Variable::TYPE_HASHTABLE:
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeHashtable'),
                        $valueRef,
                        $this->context->helper->loadValue($value)
                    );
                    $result->valueBoxHashtable = true;

                    return;
                case Variable::TYPE_OBJECT:
                    $objVal = $this->context->helper->loadValue($value);
                    if (null !== $result->writableHt && null !== $result->writableObjectKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setObjectKeyObject'),
                            $result->writableHt,
                            $result->writableObjectKey,
                            $objVal
                        );

                        return;
                    }
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeObject'),
                        $valueRef,
                        $objVal
                    );

                    return;
                case Variable::TYPE_VALUE:
                    JIT\JitValueBox::copyFromPointer(
                        $this->context,
                        $valueRef,
                        $this->valueBoxPointer($value)
                    );
                    $this->copyValueBoxJitFlags($result, $value);

                    return;
                default:
                    throw new \LogicException("Source type: {$value->type}");
            }
        } elseif ($result->type === Variable::TYPE_NATIVE_LONG && Variable::TYPE_VALUE === $value->type) {
            $longVal = $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $this->valueBoxPointer($value)
            );
            $this->context->builder->store($longVal, $result->value);

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_LONG && Variable::TYPE_NATIVE_DOUBLE === $value->type) {
            $result->free();
            $fp = $this->context->helper->loadValue($value);
            $long = $this->context->builder->fpToSi($fp, $this->context->getTypeFromString('int64'));
            $this->context->builder->store($long, $result->value);
            $result->addref();

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_DOUBLE && Variable::TYPE_NATIVE_LONG === $value->type) {
            $result->free();
            $long = $this->context->helper->loadValue($value);
            $fp = $this->context->builder->siToFp($long, $this->context->getTypeFromString('double'));
            $this->context->builder->store($fp, $result->value);
            $result->addref();

            return;
        } elseif (Variable::TYPE_VALUE === $result->type && Variable::TYPE_VALUE === $value->type) {
            JIT\JitValueBox::copyFromPointer(
                $this->context,
                $result->value,
                $this->valueBoxPointer($value)
            );
            $this->copyValueBoxJitFlags($result, $value);

            return;
        } elseif (Variable::TYPE_HASHTABLE === $result->type && Variable::TYPE_VALUE === $value->type) {
            $ht = $this->context->builder->call(
                $this->context->lookupFunction('__value__readHashtable'),
                $this->valueBoxPointer($value)
            );
            $result->free();
            $this->context->builder->store($ht, $result->value);
            $this->copyObjectPropertyBacking($result, $value);
            if (null === $result->objectPropertySlot) {
                $result->addref();
            }

            return;
        } elseif (Variable::TYPE_STRING === $result->type && Variable::TYPE_VALUE === $value->type) {
            // getenv() and similar builtins return string|false as __value__; keep the box
            // so strict comparisons against false use JitValueCompare (issue #848).
            $slot = JIT\JitValueBox::alloc($this->context);
            JIT\JitValueBox::copyFromPointer(
                $this->context,
                $slot,
                $this->valueBoxPointer($value)
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $result->addref();

            return;
        } elseif (Variable::TYPE_NATIVE_BOOL === $result->type && Variable::TYPE_VALUE === $value->type) {
            $boolVal = $this->context->castToBool($this->context->helper->loadValue($value));
            $result->free();
            $this->context->builder->store($boolVal, $result->value);
            $result->addref();

            return;
        } elseif (Variable::TYPE_OBJECT === $result->type && Variable::TYPE_VALUE === $value->type) {
            $obj = $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                $this->valueBoxPointer($value)
            );
            $result->free();
            $this->context->builder->store($obj, $result->value);
            $result->addref();

            return;
        }
        throw new \LogicException("Cannot assign operands of different types (yet): {$value->type}, {$result->type}");
    }

    private function valueBoxPointer(Variable $value): PHPLLVM\Value
    {
        return JIT\JitValueBox::valuePtrFromVariable($this->context, $value);
    }

    private function assignOperandValue(Operand $result, PHPLLVM\Value $value): void {
        if (empty($result->usages) && !$this->context->scope->variables->contains($result)) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            $this->context->makeVariableFromValueOp($value, $result);

            return;
        }
        $dest = $this->context->getVariableFromOp($result);
        if ($dest->kind !== Variable::KIND_VARIABLE) {
            throw new \LogicException('Cannot assign to a value');
        }
        $valueTy = $this->context->getStringFromType($value->typeOf());
        $destTy = $this->context->getStringFromType($dest->value->typeOf());
        if (Variable::TYPE_NATIVE_BOOL === $dest->type) {
            if ('__value__' === $valueTy || '__value__*' === $valueTy) {
                $source = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $value
                );
                $this->assignOperand($result, $source);

                return;
            }
            if ('int1' === $valueTy || 'bool' === $valueTy) {
                $dest->free();
                $this->context->builder->store($value, $dest->value);
                $dest->addref();

                return;
            }
        }
        if ('__value__*' === $valueTy && Variable::TYPE_VALUE === $dest->type) {
            $dest->free();
            JIT\JitValueBox::copyFromPointer($this->context, $dest->value, $value);
            $dest->addref();

            return;
        }
        $source = new Variable(
            $this->context,
            $this->jitTypeFromLlvmValue($value),
            Variable::KIND_VALUE,
            $value
        );
        if ($source->type === $dest->type) {
            $dest->free();
            if (Variable::TYPE_VALUE === $dest->type && ('__value__' === $destTy || '__value__*' === $destTy)) {
                $destLlvm = $dest->value->typeOf();
                $destPointsAtStruct = '__value__' === $destTy;
                if (
                    '__value__*' === $destTy
                    && \PHPLLVM\Type::KIND_POINTER === $destLlvm->getKind()
                    && '__value__' === $this->context->getStringFromType($destLlvm->getElementType())
                ) {
                    $destPointsAtStruct = true;
                }
                if ('__value__' === $valueTy && $destPointsAtStruct) {
                    $this->context->builder->store($value, $dest->value);
                    $dest->addref();
                    $this->copyValueBoxJitFlags($dest, $source);

                    return;
                }
                $ptr = '__value__*' === $valueTy
                    ? $value
                    : $this->valueBoxPointer($source);
                if ($destPointsAtStruct) {
                    JIT\JitValueBox::copyFromPointer($this->context, $dest->value, $ptr);
                } else {
                    $this->context->builder->store($ptr, $dest->value);
                }
                $dest->addref();
                $this->copyValueBoxJitFlags($dest, $source);

                return;
            }
            $toStore = $value;
            if ('__value__*' === $valueTy && '__value__' === $destTy) {
                $toStore = $this->context->builder->load($value);
            }
            $this->context->builder->store($toStore, $dest->value);
            $dest->addref();
            $this->copyValueBoxJitFlags($dest, $source);

            return;
        }
        $this->assignOperand($result, $source);
    }

    private function copyValueBoxJitFlags(Variable $dest, Variable $src): void
    {
        if (Variable::TYPE_VALUE !== $dest->type || Variable::TYPE_VALUE !== $src->type) {
            return;
        }
        $dest->valueBoxHashtable = $src->valueBoxHashtable;
        $dest->isNullConstant = $src->isNullConstant;
    }

    /** Keep borrowed object-property hashtable metadata on locals ($cfg = $this->config, #848). */
    private function copyObjectPropertyBacking(Variable $dest, Variable $src): void
    {
        $dest->objectPropertySlot = $src->objectPropertySlot;
        $dest->objectPropertyType = $src->objectPropertyType;
    }

    private function jitTypeFromLlvmValue(PHPLLVM\Value $value): int
    {
        switch ($this->context->getStringFromType($value->typeOf())) {
            case 'double':
                return Variable::TYPE_NATIVE_DOUBLE;
            case 'int1':
            case 'bool':
                return Variable::TYPE_NATIVE_BOOL;
            case 'int64':
            case 'long long':
            case 'int32':
            case 'size_t':
            case 'unsigned int':
                return Variable::TYPE_NATIVE_LONG;
            case '__string__*':
                return Variable::TYPE_STRING;
            case '__object__*':
                return Variable::TYPE_OBJECT;
            case '__hashtable__*':
                return Variable::TYPE_HASHTABLE;
            case '__value__':
            case '__value__*':
                return Variable::TYPE_VALUE;
            default:
                throw new \LogicException(
                    'Cannot infer JIT variable type from LLVM type: '
                    .$this->context->getStringFromType($value->typeOf())
                );
        }
    }

    private function compileBinaryOp(OpCode $op, Variable $left, Variable $right): Variable
    {
        if (Variable::TYPE_VALUE === $left->type && Variable::TYPE_VALUE === $right->type) {
            switch ($op->type) {
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                    return $this->compileValueBoxedBitwiseOp($op->type, $left, $right);
            }
        }

        return $this->context->helper->binaryOp($op, $left, $right);
    }

    private function compileValueBoxedBitwiseOp(int $opcodeType, Variable $left, Variable $right): Variable
    {
        $leftPtr = Variable::KIND_VARIABLE === $left->kind
            ? $left->value
            : $this->context->helper->loadValue($left);
        $rightPtr = Variable::KIND_VARIABLE === $right->kind
            ? $right->value
            : $this->context->helper->loadValue($right);
        $readLong = $this->context->lookupFunction('__value__readLong');
        $leftLong = $this->context->builder->call($readLong, $leftPtr);
        $rightLong = $this->context->builder->call($readLong, $rightPtr);
        switch ($opcodeType) {
            case OpCode::TYPE_BITWISE_AND:
                $result = $this->context->builder->bitwiseAnd($leftLong, $rightLong);
                break;
            case OpCode::TYPE_BITWISE_OR:
                $result = $this->context->builder->bitwiseOr($leftLong, $rightLong);
                break;
            case OpCode::TYPE_BITWISE_XOR:
                $result = $this->context->builder->bitwiseXor($leftLong, $rightLong);
                break;
            default:
                throw new \LogicException('Unsupported boxed bitwise opcode: '.opcode_type_name($opcodeType));
        }

        return new Variable($this->context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $result);
    }

    private function jitVariableArrayClassConstant(string $constName): ?Variable
    {
        switch (strtolower($constName)) {
            case 'native_type_map':
                return $this->jitVariableNativeTypeMapConstant();
            case 'type_map':
                return $this->jitVariableTypeMapConstant();
            default:
                return null;
        }
    }

    private function jitVariableNativeTypeMapConstant(): Variable
    {
        $slot = JIT\BasicBlockHelper::entryAlloca(
            $this->context,
            $this->context->getTypeFromString('__hashtable__*')
        );
        $result = new Variable(
            $this->context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VARIABLE,
            $slot
        );
        JIT\HashTableHelper::initArray($this->context, $result);
        foreach (JIT\Variable::NATIVE_TYPE_MAP as $typeKey => $typeName) {
            $key = Variable::fromConstantInt($this->context, $typeKey);
            $lit = new Operand\Literal($typeName);
            $lit->type = Type::string();
            $element = Variable::fromLiteral($this->context, $lit);
            JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
        }

        return $result;
    }

    private function jitVariableTypeMapConstant(): Variable
    {
        $slot = JIT\BasicBlockHelper::entryAlloca(
            $this->context,
            $this->context->getTypeFromString('__hashtable__*')
        );
        $result = new Variable(
            $this->context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VARIABLE,
            $slot
        );
        JIT\HashTableHelper::initArray($this->context, $result);
        foreach (JIT\Variable::TYPE_MAP as $typeKey => $typeValue) {
            $key = Variable::fromConstantInt($this->context, $typeKey);
            $element = Variable::fromConstantInt($this->context, $typeValue);
            JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
        }

        return $result;
    }

    /**
     * @return array<int, Variable>
     */
    private function resolveClassNameForPseudoConst(Block $block, Operand $classOp): string
    {
        if (!$classOp instanceof Operand\Literal) {
            throw new \LogicException('Class::class requires a literal class name for JIT/AOT');
        }
        $lc = strtolower($classOp->value);
        if ('self' === $lc || 'static' === $lc) {
            if (null === $block->func?->class) {
                throw new \LogicException('static::class used outside of class scope');
            }

            return $block->func->class->value;
        }

        return $classOp->value;
    }

    private function blockUsesThis(Block $block): bool
    {
        foreach ($block->orig->hoistedOperands as $hoisted) {
            if ('this' === JIT\OperandName::resolve($hoisted)) {
                return true;
            }
        }

        return false;
    }

    private function instanceMethodUsesThis(Block $block): bool
    {
        if (null === $block->func || null === $block->func->class) {
            return false;
        }
        if (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return false;
        }

        return true;
    }

    /**
     * Static parent::__construct() from an instance method passes only declared params;
     * the callee LLVM signature may still include implicit $this when blockUsesThis().
     *
     * @param array<int, Variable> $args
     *
     * @return array<int, Variable>
     */
    private function prependImplicitThisForStaticConstruct(
        Block $block,
        JIT\Call $toCall,
        array $args
    ): array {
        if (!$toCall instanceof JIT\Call\Native) {
            return $args;
        }
        if (!str_ends_with(strtolower($toCall->name), '::__construct')) {
            return $args;
        }
        if ([] === $toCall->argTypes) {
            return $args;
        }
        if ('__object__*' !== $this->context->getStringFromType($toCall->argTypes[0])) {
            return $args;
        }
        if (count($args) >= count($toCall->argTypes)) {
            return $args;
        }
        if (null === $block->func || null === $block->func->cfg) {
            return $args;
        }
        $thisVar = $this->resolveThisVariable($block);
        if (null === $thisVar) {
            return $args;
        }

        array_unshift($args, $thisVar);

        return $args;
    }

    private function resolveThisVariable(Block $block): ?Variable
    {
        if (null === $block->func || null === $block->func->cfg) {
            return null;
        }
        foreach ($block->func->cfg->hoistedOperands as $hoisted) {
            if ('this' !== JIT\OperandName::resolve($hoisted)) {
                continue;
            }
            if (!$this->context->hasVariableOpInScopes($hoisted)) {
                return null;
            }

            return $this->context->getVariableFromOpInScopes($hoisted);
        }

        if (null !== $this->context->implicitThisArgument) {
            return $this->context->implicitThisArgument;
        }

        return null;
    }

    private function collectParamDefaults(Block $block): array {
        $defaults = [];
        foreach ($block->opCodes as $op) {
            if ($op->type !== OpCode::TYPE_ARG_RECV || null === $op->arg3) {
                continue;
            }
            if (!isset($block->constants[$op->arg3])) {
                continue;
            }
            $defaultIdx = $op->arg2;
            if ($this->instanceMethodUsesThis($block)) {
                ++$defaultIdx;
            }
            $defaults[$defaultIdx] = $this->jitVariableFromVmConstant($block->constants[$op->arg3]);
        }
        return $defaults;
    }

    private function jitVariableFromVmConstant(VM\Variable $vm): Variable {
        switch ($vm->type) {
            case VM\Variable::TYPE_INTEGER:
                return Variable::fromConstantInt($this->context, $vm->toInt());
            case VM\Variable::TYPE_STRING:
                $lit = new Operand\Literal($vm->toString());
                $lit->type = Type::string();
                return Variable::fromLiteral($this->context, $lit);
            case VM\Variable::TYPE_FLOAT:
                $lit = new Operand\Literal($vm->toFloat());
                $lit->type = Type::float();
                return Variable::fromLiteral($this->context, $lit);
            case VM\Variable::TYPE_BOOLEAN:
                $lit = new Operand\Literal($vm->toBool());
                $lit->type = Type::bool();
                return Variable::fromLiteral($this->context, $lit);
            case VM\Variable::TYPE_NULL:
                $nullVar = new Variable(
                    $this->context,
                    Variable::TYPE_NULL,
                    Variable::KIND_VALUE,
                    $this->context->getTypeFromString('__value__*')->constNull()
                );
                $nullVar->isNullConstant = true;

                return $nullVar;
            case VM\Variable::TYPE_ARRAY:
                return $this->jitVariableFromVmArray($vm);
            default:
                throw new \LogicException('Unsupported default parameter type for JIT (vm type ' . $vm->type . ')');
        }
    }

    private function jitVariableFromVmArray(VM\Variable $vm): Variable
    {
        $ht = $vm->toArray();
        $jitHt = JIT\HashTableHelper::alloc($this->context);
        $var = new Variable(
            $this->context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $jitHt
        );
        if (0 === $ht->getNumElements()) {
            return $var;
        }
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            JIT\HashTableHelper::addElement(
                $this->context,
                $var,
                $this->jitVariableFromVmConstant($value),
                $this->jitVariableFromVmConstant($key)
            );
        }

        return $var;
    }

    private function loadPropertyFetchReceiver(Operand $objOp): PHPLLVM\Value
    {
        $var = $this->context->getVariableFromOp($objOp);
        if (Variable::TYPE_OBJECT === $var->type) {
            return $this->context->helper->loadValue($var);
        }
        if (Variable::TYPE_VALUE === $var->type) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                JIT\JitValueBox::valuePtrFromVariable($this->context, $var)
            );
        }

        throw new \LogicException(
            'Property fetch receiver must be object or object-valued property, got '
            .Variable::getStringType($var->type)
        );
    }

    private static function foreachContainerUserType(Operand $arrayOp): ?string
    {
        $userType = $arrayOp->type->userType ?? null;
        if (null !== $userType && '' !== $userType) {
            return $userType;
        }
        if (null !== $arrayOp->type && Variable::TYPE_HASHTABLE === Variable::getTypeFromType($arrayOp->type)) {
            $decl = $arrayOp->type->userType ?? null;
            if (null !== $decl && 0 === strcasecmp($decl, 'SplObjectStorage')) {
                return 'SplObjectStorage';
            }
        }

        return null;
    }


    private function ensureJitGlobal(string $name): Variable
    {
        if (!isset($this->context->jitGlobalVariables[$name])) {
            $slot = JIT\JitValueBox::alloc($this->context);
            $this->context->jitGlobalVariables[$name] = new Variable(
                $this->context,
                Variable::TYPE_VALUE,
                Variable::KIND_VARIABLE,
                $slot
            );
        }

        return $this->context->jitGlobalVariables[$name];
    }

}
