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
use PHPCfg\ErrorSuppressBlock;
use PHPCfg\Func;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as VarOperand;
use PHPCompiler\Compiler\AttributeNames;
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

    /** True when this CFG block emitted declare(ticks=N) enter (#3343). */
    public bool $tickScopeOpened = false;

    /** __COMPILER_HALT_OFFSET__ when the script contains __halt_compiler() (#5455). */
    public ?int $haltCompilerOffset = null;

    /** @var array<int, int> scope slot index => Variable::TYPE_* for typed parameters */
    public array $paramTypeConstraints = [];

    /** @var array<int, string> scope slot => class/interface name for object type-hinted parameters (#6145) */
    public array $paramClassConstraints = [];

    /** @var array<int, string> scope slot => declared type label for error messages (#6145) */
    public array $paramDeclaredTypeLabels = [];

    /** Parameter scope slots declared `iterable` (array|Traversable union, #4829). */
    public array $paramIterableSlots = [];

    /** Parameter scope slots declared `callable` (#17742). */
    public array $paramCallableSlots = [];

    /** Parameter scope slots declared standalone `never` (#6633). */
    public array $paramNeverSlots = [];

    /** @var array<int, 'true'|'false'> standalone bool literal parameter types (#4784) */
    public array $paramLiteralBoolTypes = [];

    /** @var array<int, int> typed variadic element constraints — not applied to the packed array local (#4185) */
    public array $paramVariadicElementTypeConstraints = [];

    /** @var array<int, GenericArrayTypeSpec> generic list/array parameter types (#3705) */
    public array $paramGenericArrayTypeSpecs = [];

    /** @var array<int, GenericArrayTypeSpec> typed variadic element array specs (#4185) */
    public array $paramVariadicElementGenericArrayTypeSpecs = [];

    /** @var array<int, list<string>> */
    public array $paramIntersectionConstraints = [];

    /** @var array<int, string> declared intersection type labels for TypeError messages */
    public array $paramIntersectionDisplayLabels = [];

    /** @var array<int, list<string>> typed variadic element intersection constraints (#4185) */
    public array $paramVariadicElementIntersectionConstraints = [];

    /** @var array<int, string> typed variadic element intersection type labels (#6819) */
    public array $paramVariadicElementIntersectionDisplayLabels = [];

    /** @var array<int, Op\Type> declared parameter types for reflection (#3355). */
    public array $paramDeclaredTypes = [];

    /** @var array<string, Op\Type> lowercase class const name => declared type (#5954). */
    public array $classConstDeclaredTypes = [];

    /** Declared return type AST for reflection (#3355), or null when untyped. */
    public ?Op\Type $returnDeclaredType = null;

    /** @var array<int, list<array{kind: string, interfaces?: list<string>, name?: string}>> */
    public array $paramDnfConstraints = [];

    /** @var array<int, list<array{kind: string, interfaces?: list<string>, name?: string}>> typed variadic element DNF (#4185) */
    public array $paramVariadicElementDnfConstraints = [];

    /** DNF return type arms (#3094), or null when untyped / non-DNF. */
    public ?array $returnDnfConstraints = null;

    /** Declared scalar return type for this function (issue #205), or null when untyped. */
    public ?int $returnTypeConstraint = null;

    /** Declared object return class name (issue #10333), or null when untyped / non-class. */
    public ?string $returnClassConstraint = null;

    /** Declared object return type label for errors (#10333), or null. */
    public ?string $returnDeclaredTypeLabel = null;

    /** Standalone `: true` / `: false` return type (#4784), or null. */
    public ?string $returnLiteralBoolType = null;

    /** Declared `: void` return — non-null returns are rejected. */
    public bool $returnTypeVoid = false;

    /** Declared `: never` return — implicit fall-off raises at runtime (#1358, #4206). */
    public bool $returnTypeNever = false;

    /** Declared `: static` return — late-bound object type (issue #3412). */
    public bool $returnTypeStatic = false;

    /** Explicit `: mixed` return — still requires a returned value (#26485). */
    public bool $returnTypeMixed = false;

    /** Parameter index (0-based, excluding $this) that receives a packed trailing-arg array (#197). */
    public ?int $variadicParamIndex = null;

    /** Declared parameter names by index (issue #168). */
    public array $paramNames = [];

    /** Parameter indices declared with `&$param` (issue #140). */
    public array $paramByRef = [];

    /** Parameter indices marked `#[\SensitiveParameter]` (issue #3351). */
    public array $paramSensitive = [];

    /** `#[\NoDiscard]` on this function/method (#5078). */
    public bool $noDiscard = false;

    /** Optional message from `#[\NoDiscard(message: ...)]`. */
    public ?string $noDiscardMessage = null;

    /** `#[\Deprecated]` on this function/method for JIT/AOT use-site notices (#27331). */
    public ?\PHPCompiler\Compiler\DeprecatedMetadata $deprecated = null;

    /** Parameter scope slots with non-nullable type and `= null` default (Zend 8.2 implicit nullable, #4449). */
    public array $paramImplicitNullable = [];

    /** Parameter indices with runtime `new` default init fragments (#6652). */
    public array $paramRuntimeDefaultInitBlocks = [];

    /** Result slot in {@see self::$paramRuntimeDefaultInitBlocks} per parameter index (#6652). */
    public array $paramRuntimeDefaultResultSlots = [];

    /**
     * Parameter index => constant-default name for ReflectionParameter::isDefaultValueConstant()
     * / getDefaultValueConstantName() (#22026, ext/reflection/php_reflection.c).
     *
     * @var array<int, string>
     */
    public array $paramDefaultConstantNames = [];

    /** Function body contains `yield` (issue #167). */
    public bool $isGenerator = false;

    /** Closure `use ($var)` slots populated at call from {@see ClosureState} (issue #72). */
    public array $closureCaptureSlots = [];

    /** Closure `use (&$var)` slots that alias enclosing storage at call (issue #72). */
    public array $closureCaptureByRef = [];

    /** @var array<int, string> Closure `use` slot => captured variable name (#17089). */
    public array $closureCaptureSlotNames = [];

    /** Arrow function body: register outer lexical reads for auto-capture (#4944, #4952, #10304). */
    public bool $arrowAutoCapture = false;

    /** Resolved absolute paths for TYPE_INCLUDE opcodes (arg3 index, issue #54). */
    public array $literalIncludePaths = [];

    /**
     * Include_/Eval_ CFG exprs already lowered in this block — avoid double TYPE_INCLUDE (#25852).
     *
     * @var array<int, true> spl_object_id(Op\Expr) => true
     */
    public array $emittedIncludeOrEvalExprIds = [];

    /**
     * phpc_deploy_path() + suffix includes (arg3 index, issue #623).
     *
     * @var array<int, array{rel: string, fallback: string, suffix: string, compile: ?string}>
     */
    public array $deployIncludePaths = [];

    /**
     * Operand slots for deferred inline array literals — must survive JUMPIF dead-temp release
     * until INIT_ARRAY materialization (#14134, Zend/zend_compile.c).
     *
     * @var array<int, true>
     */
    public array $deferredArrayLiteralKeepSlots = [];

    /**
     * PHP 8.4+ Zend closure display name {@code {closure:…:line}} for this body (#30076).
     *
     * Set at compile time on anonymous/arrow Func blocks; used by TypeError / Reflection / debugInfo.
     */
    public ?string $closureRichDisplayName = null;

    /**
     * Declaring class for method-scoped closures (TypeError {@code Class::{closure…}}, #30076 / #29953).
     */
    public ?string $closureDeclaringClass = null;

    /** Absolute entry script path when CFG filename attribute is missing (issue #707). */
    private string $scriptPathOverride = '';

    /** Preprocessed source for bundle line reverse-mapping (#13201). */
    private ?string $compileSource = null;

    /** Operand / cfg-Var roots assigned in this block (not inherited reads, #2059). */
    private \SplObjectStorage $localWrittenVars;

    /** @var array<string, true> php-cfg may emit distinct Var operands per name (#15658 method_exists $method). */
    private array $localWrittenVarNames = [];

    /** php-cfg assign.var root => result slot for `$local = …` reads (#5644). */
    private \SplObjectStorage $namedAssignDestSlots;

    /** @var array<int, true> */
    private array $namedAssignDestSlotIndexes = [];

    /** assign.result temp => CV lvalue slot for reads after in-place mutation (#15125). */
    private array $assignResultToLvalueSlot = [];

    public function __construct(?CfgBlock $block) {
        $this->orig = $block;
        $this->scope = new \SplObjectStorage;
        $this->args = new \SplObjectStorage;
        $this->localWrittenVars = new \SplObjectStorage;
        $this->namedAssignDestSlots = new \SplObjectStorage;
    }

    public function registerNamedAssignDest(Operand $varRoot, int $slot): void
    {
        $this->namedAssignDestSlots[$varRoot] = $slot;
        $this->namedAssignDestSlotIndexes[$slot] = true;
    }

    /** True when $slot is a registered `$local = …` assign destination (#16040, #24017). */
    public function isNamedAssignDestSlot(int $slot): bool
    {
        return isset($this->namedAssignDestSlotIndexes[$slot]);
    }

    public function registerAssignResultLvalue(int $resultSlot, int $lvalueSlot): void
    {
        $this->assignResultToLvalueSlot[$resultSlot] = $lvalueSlot;
    }

    public function lvalueSlotForAssignResult(int $resultSlot): ?int
    {
        return $this->assignResultToLvalueSlot[$resultSlot] ?? null;
    }

    public function slotForNamedAssignDest(Operand $operand): ?int
    {
        $root = self::cfgVarRoot($operand);
        if (null !== $root && $this->namedAssignDestSlots->contains($root)) {
            return $this->namedAssignDestSlots[$root];
        }
        // php-cfg distinct Var operands per name — assign.var vs later read (#15658).
        $name = self::resolveVariableName($operand);
        if (null === $name || '' === $name) {
            return null;
        }
        foreach ($this->namedAssignDestSlots as $storedRoot) {
            if (self::resolveVariableName($storedRoot) === $name) {
                return $this->namedAssignDestSlots[$storedRoot];
            }
        }

        return null;
    }

    public function setScriptPath(string $path): void
    {
        $this->scriptPathOverride = ScriptStack::normalize($path);
    }

    public function setCompileSource(?string $source): void
    {
        $this->compileSource = $source;
    }

    public function compileSource(): ?string
    {
        return $this->compileSource;
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

    /**
     * User function/method/closure body — unbound locals must not inherit {main} globals (#5454).
     */
    public function blocksScriptGlobalInheritance(): bool
    {
        return null !== $this->func
            && !$this->isMainScript()
            && !$this->inheritUndefinedLocals;
    }

    public function getOperand(int $offset): ?Operand {
        foreach ($this->namedAssignDestSlots as $root => $slot) {
            if ($slot === $offset) {
                return $root;
            }
        }
        $fallback = null;
        foreach ($this->scope as $operand) {
            if ($this->scope[$operand] !== $offset) {
                continue;
            }
            if ($operand instanceof VarOperand) {
                return $operand;
            }
            if ($operand instanceof Operand\Literal) {
                // Keep the first Literal at this slot. inheritScopeFrom appends parent
                // Literals after the callee's; overwriting would make CONST_FETCH resolve
                // e.g. parent "defined" instead of callee "true" (#29111).
                if (null === $fallback) {
                    $fallback = $operand;
                }

                continue;
            }
            if (null === $fallback) {
                $fallback = $operand;
            }
        }

        return $fallback;
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

    /** Scope slot for a declared parameter by name (php-cfg clones Var operands per read site, #16264). */
    public function paramSlotForName(string $name): ?int
    {
        $paramIdx = array_search($name, $this->paramNames, true);
        if (false === $paramIdx) {
            return null;
        }

        return $this->paramSlotForIndex((int) $paramIdx) ?? $this->slotIndexForVariableName($name);
    }

    /**
     * Scope slot receiving TYPE_ARG_RECV for parameter index (excludes $this; #21984).
     */
    public function paramSlotForIndex(int $paramIdx): ?int
    {
        foreach ($this->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV === $op->type && (int) $op->arg2 === $paramIdx) {
                return (int) $op->arg1;
            }
        }

        return null;
    }

    /**
     * Closure capture CV slots are read-only for unrelated expression writes (#17089).
     */
    public function closureCaptureSlotWritableForOperand(int $slot, Operand $operand): bool
    {
        if (!isset($this->closureCaptureSlots[$slot])) {
            return true;
        }
        $captureName = $this->closureCaptureSlotNames[$slot] ?? null;
        if (null === $captureName || '' === $captureName) {
            return false;
        }
        foreach ([$operand, self::cfgVarRoot($operand)] as $candidate) {
            if (!$candidate instanceof Operand) {
                continue;
            }
            $name = self::resolveVariableName($candidate);
            if ($captureName === $name) {
                return true;
            }
        }

        return false;
    }

    public function getVarSlot(Operand $operand, bool $isRead): int {
        if ($isRead) {
            $name = self::resolveVariableName($operand);
            if (null !== $name && '' !== $name) {
                $paramSlot = $this->paramSlotForName($name);
                if (null !== $paramSlot) {
                    $this->scope[$operand] = $paramSlot;
                    if ($this->shouldRegisterInheritedArg($operand)) {
                        $this->args[$operand] = $paramSlot;
                    }

                    return $paramSlot;
                }
            }
            // Prefer CV assign-dest over a stale in-scope SSA binding (#24540): coalesce-for-assign
            // may leave Temporary/$local mappings on fresh slots before the CV is registered.
            $namedDest = $this->slotForNamedAssignDest($operand);
            if (null !== $namedDest) {
                $this->scope[$operand] = $namedDest;
                if ($this->shouldRegisterInheritedArg($operand)) {
                    $this->args[$operand] = $namedDest;
                }

                return $namedDest;
            }
            if ($this->scope->contains($operand)) {
                $existing = $this->scope[$operand];
                if ($this->shouldRegisterInheritedArg($operand)) {
                    $this->args[$operand] = $existing;
                }

                return $existing;
            }
            if (null !== $name && '' !== $name) {
                $namedDest = $this->slotIndexForVariableName($name);
                if (null !== $namedDest) {
                    $this->scope[$operand] = $namedDest;
                    if ($this->shouldRegisterInheritedArg($operand)) {
                        $this->args[$operand] = $namedDest;
                    }

                    return $namedDest;
                }
            }
        }
        if ($this->scope->contains($operand)) {
            $existing = $this->scope[$operand];
            if ($isRead || $this->closureCaptureSlotWritableForOperand($existing, $operand)) {
                if ($isRead && $this->shouldRegisterInheritedArg($operand)) {
                    $this->args[$operand] = $existing;
                }
                if (!$isRead) {
                    $this->markLocallyWritten($operand);
                }

                return $existing;
            }
        }
        // php-cfg may wrap named locals in temporaries after while-assign conditions; bind by
        // variable name before call-site temp clone reuse (#10702, #8560).
        $namedRoot = self::cfgVarRoot($operand);
        if ($namedRoot instanceof VarOperand) {
            $name = self::resolveVariableName($namedRoot);
            if (null !== $name) {
                $existing = $this->slotIndexForVariableName($name);
                if (null !== $existing && ($isRead || $this->closureCaptureSlotWritableForOperand($existing, $operand))) {
                    $this->scope[$operand] = $existing;
                    if ($isRead && $this->shouldRegisterInheritedArg($operand)) {
                        $this->args[$operand] = $existing;
                    }
                    if (!$isRead) {
                        $this->markLocallyWritten($operand);
                    }

                    return $existing;
                }
            }
        }
        // Call-site arg clones wrap inline Expr temps; reuse the producer slot (#8560, #3553).
        if ($operand instanceof Temporary && null !== $operand->original && $this->scope->contains($operand->original)) {
            $existing = $this->scope[$operand->original];
            if ($isRead || $this->closureCaptureSlotWritableForOperand($existing, $operand)) {
                $this->scope[$operand] = $existing;
                if ($isRead && $this->shouldRegisterInheritedArg($operand)) {
                    $this->args[$operand] = $existing;
                }
                if (!$isRead) {
                    $this->markLocallyWritten($operand);
                }

                return $existing;
            }
        }
        $name = self::resolveVariableName($operand);
        if (null !== $name) {
            $existing = $this->slotIndexForVariableName($name);
            if (null !== $existing && ($isRead || $this->closureCaptureSlotWritableForOperand($existing, $operand))) {
                $this->scope[$operand] = $existing;
                if ($isRead && $this->shouldRegisterInheritedArg($operand)) {
                    $this->args[$operand] = $existing;
                }
                if (!$isRead) {
                    $this->markLocallyWritten($operand);
                }

                return $existing;
            }
        }
        $cfgVar = self::cfgVarRoot($operand);
        if (null !== $cfgVar) {
            foreach ($this->scope as $scopedOp) {
                if (self::cfgVarRoot($scopedOp) === $cfgVar) {
                    $existing = $this->scope[$scopedOp];
                    if (!$isRead && !$this->closureCaptureSlotWritableForOperand($existing, $operand)) {
                        continue;
                    }
                    $this->scope[$operand] = $existing;
                    if ($isRead && $this->shouldRegisterInheritedArg($operand)) {
                        $this->args[$operand] = $existing;
                    }
                    if (!$isRead) {
                        $this->markLocallyWritten($operand);
                    }

                    return $existing;
                }
            }
        }
        $next = $this->nextScopeSlot();
            $this->scope[$operand] = $next;
            if ($isRead && $this->shouldRegisterInheritedArg($operand)) {
                $this->args[$operand] = $next;
            }
            if (!$isRead && !$operand instanceof Operand\Literal) {
                $this->markLocallyWritten($operand);
            }

        return $this->scope[$operand];
    }

    /** Reads that must bind from caller/include/merge — not same-block locals (#3787, #2059). */
    private function shouldRegisterInheritedArg(Operand $operand): bool
    {
        if (null === self::resolveVariableName($operand)) {
            return false;
        }
        if ($this->isLocallyWritten($operand)) {
            return false;
        }
        if ($this->blocksScriptGlobalInheritance() && !$this->arrowAutoCapture) {
            return false;
        }
        // Try/catch/finally bodies inherit parent slots directly (#9114, Zend/zend_execute.c).
        if ($this->inheritUndefinedLocals) {
            return false;
        }

        return true;
    }

    private function markLocallyWritten(Operand $operand): void
    {
        $this->localWrittenVars[$operand] = true;
        $root = self::cfgVarRoot($operand);
        if (null !== $root) {
            $this->localWrittenVars[$root] = true;
        }
        $name = self::resolveVariableName($operand);
        if (null !== $name && '' !== $name) {
            $this->localWrittenVarNames[$name] = true;
        }
        $slots = [];
        if ($this->scope->contains($operand)) {
            $slots[(int) $this->scope[$operand]] = true;
        }
        if (null !== $root && $this->scope->contains($root)) {
            $slots[(int) $this->scope[$root]] = true;
        }
        if (null !== $name && '' !== $name) {
            $named = $this->slotIndexForVariableName($name);
            if (null !== $named) {
                $slots[$named] = true;
            }
        }
        foreach ($slots as $slot => $_) {
            unset($this->constants[$slot]);
            foreach ($this->scope as $scopedOp) {
                if ($this->scope[$scopedOp] !== $slot) {
                    continue;
                }
                if ($scopedOp instanceof Operand\Literal && $scopedOp !== $operand) {
                    $this->scope->detach($scopedOp);
                }
            }
        }
    }

    private function isLocallyWritten(Operand $operand): bool
    {
        if (isset($this->localWrittenVars[$operand])) {
            return true;
        }
        $root = self::cfgVarRoot($operand);
        if (null !== $root && isset($this->localWrittenVars[$root])) {
            return true;
        }
        $name = self::resolveVariableName($operand);
        if (null !== $name && '' !== $name && isset($this->localWrittenVarNames[$name])) {
            return true;
        }

        return false;
    }

    public function hasLocallyWrittenVariableName(string $name): bool
    {
        return isset($this->localWrittenVarNames[$name]);
    }

    /** Drop stale literal operands/constants on a live CV slot before echo (#23842). */
    public function invalidateCompileTimeSlot(int $slot): void
    {
        unset($this->constants[$slot]);
        foreach ($this->scope as $scopedOp) {
            if ($this->scope[$scopedOp] !== $slot) {
                continue;
            }
            if ($scopedOp instanceof Operand\Literal) {
                $this->scope->detach($scopedOp);
            }
        }
    }

    public function variableNameForSlot(int $slot): ?string
    {
        foreach ($this->namedAssignDestSlots as $root => $destSlot) {
            if ($destSlot === $slot) {
                $name = self::resolveVariableName($root);
                if (null !== $name && '' !== $name) {
                    return $name;
                }
            }
        }
        foreach ($this->scope as $operand) {
            if ($this->scope[$operand] !== $slot) {
                continue;
            }
            $name = self::resolveVariableName($operand);
            if (null !== $name && '' !== $name) {
                return $name;
            }
        }

        return null;
    }

    /** Bind operand to a fresh slot (?: throw arm must not alias merge phi slot, #3802). */
    public function forceFreshVarSlot(Operand $operand, ?int $excludeSlot = null): int
    {
        $slot = $this->nextScopeSlot();
        if (null !== $excludeSlot && $slot <= $excludeSlot) {
            $slot = $excludeSlot + 1;
        }
        $this->scope[$operand] = $slot;

        return $slot;
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
        if (isset($this->constants[$slot]) && !$this->compileTimeConstantsMatch($this->constants[$slot], $const)) {
            $slot = $this->forceFreshVarSlot($operand);
        }
        $this->constants[$slot] = $const;

        return $slot;
    }

    /** True when two compile-time slot constants are the same value (#15902). */
    private function compileTimeConstantsMatch(Variable $existing, Variable $incoming): bool
    {
        if ($existing->type !== $incoming->type) {
            return false;
        }

        return match ($existing->type) {
            Variable::TYPE_INTEGER => $existing->toInt() === $incoming->toInt(),
            Variable::TYPE_FLOAT => $existing->toFloat() === $incoming->toFloat(),
            Variable::TYPE_BOOLEAN => $existing->toBool() === $incoming->toBool(),
            Variable::TYPE_STRING => $existing->toString() === $incoming->toString(),
            Variable::TYPE_NULL => true,
            default => false,
        };
    }

    /**
     * Per-run copy of a compile-time constant for frame scope (#12040, ServeCompileCache).
     *
     * Cached {@see Block} instances must not share {@see Variable} cells with execution
     * scope — builtins may coerce/mutate argument operands in place.
     */
    private function scopeConstantVariable(int $slot): Variable
    {
        $copy = new Variable();
        $copy->duplicateFrom($this->constants[$slot]);

        return $copy;
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
            // Named locals keep their CV slot; sibling &&/|| phi maps must not rebind (#15183, #16040).
            $name = self::resolveVariableName($root);
            if (null !== $name && '' !== $name) {
                continue;
            }
            if ($this->namedAssignDestSlots->contains($root)) {
                continue;
            }
            $this->scope[$root] = $slot;
            if ($sibling->args->contains($root) || $sibling->isArgSlot($slot)) {
                $this->args[$root] = $slot;
            }
        }
    }

    /** Yields [VarOperand root, int scope slot] pairs. */
    public function eachCfgVarRootSlot(): \Generator
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
        if ($this->namedAssignDestSlots->contains($root)) {
            $dest = $this->namedAssignDestSlots[$root];
            if ($dest !== $slot) {
                return;
            }
        }
        $name = self::resolveVariableName($root);
        if (null !== $name && '' !== $name) {
            $existing = $this->slotForNamedAssignDest($root);
            if (null !== $existing && $existing !== $slot) {
                return;
            }
        }
        if (!$this->scope->contains($root)) {
            $this->scope[$root] = $slot;
        }
    }

    /** Ensure a ?: merge phi slot is present in this branch frame (#5506). */
    public function bindScopeSlot(Operand $operand, int $slot): void
    {
        $name = self::resolveVariableName($operand);
        if (null !== $name && '' !== $name) {
            $existing = $this->slotIndexForVariableName($name);
            if (null !== $existing && $existing !== $slot) {
                return;
            }
        }
        if (!$this->scope->contains($operand)) {
            $this->scope[$operand] = $slot;
        }
    }

    /** Error-suppress exit may need to override an inherited empty slot (#10336). */
    public function forceBindScopeSlot(Operand $operand, int $slot): void
    {
        $this->scope[$operand] = $slot;
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
     *
     * @param bool $remapCollidingSlots When true (literal include/require inlining),
     *        assign fresh slots if the parent's index is already used so {@see getOperand()}
     *        cannot return a callee temp for a parent local (#22845 MiniWebApp nested
     *        $appName). CFG branch inheritance must keep shared slot indices (default).
     *        Zend full-spine may set PHP_COMPILER_INCLUDE_SCOPE_REMAP=0 so VarOperand
     *        indices stay shared for pace (#22642); Literal operands still remapped on
     *        collision so CONST_FETCH cannot resolve a parent string (e.g. "defined")
     *        instead of the callee's "true" (#29111).
     */
    public function inheritScopeFrom(Block $parent, bool $remapCollidingSlots = false): void
    {
        foreach ($parent->scope as $operand) {
            if ($this->scope->contains($operand)) {
                continue;
            }
            $parentSlot = $parent->scope[$operand];
            $slot = $parentSlot;
            $occupant = $this->operandForScopeSlot($slot);
            if (null !== $occupant) {
                if ($remapCollidingSlots) {
                    $slot = $this->nextScopeSlot();
                } elseif ($operand instanceof Operand\Literal) {
                    // REMAP=0 keeps VarOperand indices shared; never alias parent
                    // Literals onto callee slots used by CONST_FETCH (#29111).
                    $slot = $this->nextScopeSlot();
                }
            }
            $this->scope[$operand] = $slot;
            if ($parent->args->contains($operand)) {
                $this->args[$operand] = $slot;
            }
            if (isset($parent->constants[$parentSlot]) && !isset($this->constants[$slot])) {
                $this->constants[$slot] = $parent->constants[$parentSlot];
            }
        }
        foreach ($parent->namedAssignDestSlotIndexes as $slot => $_) {
            $this->namedAssignDestSlotIndexes[$slot] = true;
        }
        foreach ($parent->namedAssignDestSlots as $root) {
            if (!$this->namedAssignDestSlots->contains($root)) {
                $this->namedAssignDestSlots[$root] = $parent->namedAssignDestSlots[$root];
            }
        }
        foreach ($parent->assignResultToLvalueSlot as $resultSlot => $lvalueSlot) {
            if (!isset($this->assignResultToLvalueSlot[$resultSlot])) {
                $this->assignResultToLvalueSlot[$resultSlot] = $lvalueSlot;
            }
        }
        // literal/deploy include path tables are per-block; inheriting parent paths breaks
        // arg3 indices and can recurse into the wrong TU (layout vs partial, issue #784).
        if (null !== $parent->func) {
            $this->func = $parent->func;
            $this->strictTypes = $parent->strictTypes;
            $this->returnTypeConstraint = $parent->returnTypeConstraint;
            $this->returnClassConstraint = $parent->returnClassConstraint;
            $this->returnDeclaredTypeLabel = $parent->returnDeclaredTypeLabel;
            $this->returnDnfConstraints = $parent->returnDnfConstraints;
            $this->returnTypeVoid = $parent->returnTypeVoid;
            $this->returnTypeNever = $parent->returnTypeNever;
            $this->returnTypeStatic = $parent->returnTypeStatic;
            $this->returnTypeMixed = $parent->returnTypeMixed;
            $this->returnDeclaredType = $parent->returnDeclaredType;
            $this->closureRichDisplayName = $parent->closureRichDisplayName;
            $this->closureDeclaringClass = $parent->closureDeclaringClass;
            $this->paramDeclaredTypes = $parent->paramDeclaredTypes;
            $this->paramTypeConstraints = $parent->paramTypeConstraints;
            $this->paramClassConstraints = $parent->paramClassConstraints;
            $this->paramDeclaredTypeLabels = $parent->paramDeclaredTypeLabels;
            $this->paramIterableSlots = $parent->paramIterableSlots;
            $this->paramCallableSlots = $parent->paramCallableSlots;
            $this->paramNeverSlots = $parent->paramNeverSlots;
            $this->paramLiteralBoolTypes = $parent->paramLiteralBoolTypes;
            $this->returnLiteralBoolType = $parent->returnLiteralBoolType;
            $this->paramIntersectionConstraints = $parent->paramIntersectionConstraints;
            $this->paramIntersectionDisplayLabels = $parent->paramIntersectionDisplayLabels;
            $this->paramVariadicElementIntersectionConstraints = $parent->paramVariadicElementIntersectionConstraints;
            $this->paramVariadicElementIntersectionDisplayLabels = $parent->paramVariadicElementIntersectionDisplayLabels;
            $this->paramDnfConstraints = $parent->paramDnfConstraints;
            $this->paramNames = $parent->paramNames;
            $this->paramByRef = $parent->paramByRef;
            $this->paramSensitive = $parent->paramSensitive;
            $this->paramImplicitNullable = $parent->paramImplicitNullable;
            $this->paramRuntimeDefaultInitBlocks = $parent->paramRuntimeDefaultInitBlocks;
            $this->paramRuntimeDefaultResultSlots = $parent->paramRuntimeDefaultResultSlots;
            $this->paramDefaultConstantNames = $parent->paramDefaultConstantNames;
            $this->noDiscard = $parent->noDiscard;
            $this->noDiscardMessage = $parent->noDiscardMessage;
            $this->deprecated = $parent->deprecated;
        }
        $this->arrowAutoCapture = $parent->arrowAutoCapture;
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
        $fallback = null;
        foreach ($this->scope as $operand) {
            if (self::resolveVariableName($operand) !== $name) {
                continue;
            }
            $slot = $this->scope[$operand];
            if (isset($this->namedAssignDestSlotIndexes[$slot])) {
                return $slot;
            }
            if (null === $fallback) {
                $fallback = $slot;
            }
        }

        return $fallback;
    }

    /** True when $slot holds a named local ($a), not a compiler temporary (#5340). */
    public function isNamedVariableSlot(int $slot): bool
    {
        if (isset($this->namedAssignDestSlotIndexes[$slot])) {
            return true;
        }
        foreach ($this->scope as $operand) {
            if ($this->scope[$operand] === $slot && null !== self::resolveVariableName($operand)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when an assign RHS/result temp may be nulled after TYPE_ASSIGN (#4096, #6758).
     * Chained assignment keeps the inner result temp alive until the outer assign reads it.
     */
    public function assignTempSlotIsDead(int $slot): bool
    {
        if (isset($this->constants[$slot]) || $this->isNamedVariableSlot($slot)) {
            return false;
        }
        $operand = $this->getOperand($slot);
        if (null === $operand) {
            return true;
        }
        if ([] !== $operand->usages) {
            return false;
        }

        return !$this->assignResultSlotConsumedByLaterOp($slot);
    }

    /**
     * php-cfg may leave assign-expression result temps without usages when the value feeds a call arg (#6758, #9405).
     */
    private function assignResultSlotConsumedByLaterOp(int $slot): bool
    {
        $afterProducer = false;
        for ($i = 0; $i < $this->nOpCodes; ++$i) {
            $op = $this->opCodes[$i] ?? null;
            if (null === $op) {
                continue;
            }
            if (OpCode::TYPE_ASSIGN === $op->type && (int) $op->arg1 === $slot) {
                $afterProducer = true;
                continue;
            }
            if (!$afterProducer) {
                continue;
            }
            if ($this->opCodeReadsScopeSlot($op, $slot)) {
                return true;
            }
        }

        return false;
    }

    private function opCodeReadsScopeSlot(OpCode $op, int $slot): bool
    {
        if (OpCode::TYPE_ASSIGN === $op->type) {
            return (int) $op->arg2 === $slot || (int) $op->arg3 === $slot;
        }
        // Some opcode args store source line numbers, not scope slots. Treating them as
        // value reads keeps assign-result object temps alive until shutdown and defers
        // __destruct past `$a = null` / reassignment (#23484, re-#6456; see OpCode.php).
        foreach ($this->opCodeValueScopeArgs($op) as $arg) {
            if (null !== $arg && (int) $arg === $slot) {
                return true;
            }
        }

        return false;
    }

    /**
     * Opcode args that read/write scope values (excludes startLine / call-site line immediates).
     *
     * @return list<int|null>
     */
    public function opCodeValueScopeArgs(OpCode $op): array
    {
        return match ($op->type) {
            OpCode::TYPE_ECHO => [$op->arg1],
            OpCode::TYPE_PRINT => [$op->arg1, $op->arg2],
            OpCode::TYPE_FUNCCALL_EXEC_RETURN => [$op->arg1],
            OpCode::TYPE_FUNCCALL_EXEC_NORETURN => [],
            OpCode::TYPE_NEW => [$op->arg1, $op->arg2],
            OpCode::TYPE_RETURN => [$op->arg1],
            OpCode::TYPE_RETURN_VOID => [],
            OpCode::TYPE_ARRAY_SPREAD => [$op->arg1, $op->arg2],
            default => [$op->arg1, $op->arg2, $op->arg3],
        };
    }

    public function markDeferredArrayLiteralKeepSlot(int $slot): void
    {
        $this->deferredArrayLiteralKeepSlots[$slot] = true;
        foreach ($this->parents as $parent) {
            if ($parent instanceof self) {
                $parent->markDeferredArrayLiteralKeepSlot($slot);
            }
        }
    }

    /**
     * Match unhandled-error lowering reads the scrutinee again on JUMPIF targets (#13955).
     * Concat/?? chains read prefix temps on COALESCE/JUMP merge arms (#17375).
     * Nullsafe merge (block3) may pack earlier array-element temps after a method call (#28555).
     */
    public function scopeSlotReadInJumpTargets(int $slot): bool
    {
        foreach ($this->opCodes as $op) {
            foreach ($this->controlFlowBranchTargets($op) as $target) {
                if (!$target instanceof self) {
                    continue;
                }
                $seen = [];
                if ($target->blockReadsScopeSlotTree($slot, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, self|null>
     */
    private function controlFlowBranchTargets(OpCode $op): array
    {
        return match ($op->type) {
            OpCode::TYPE_JUMPIF, OpCode::TYPE_COALESCE => [$op->block1, $op->block2],
            // Nullsafe merge (block3) holds post-?-> consumers (e.g. INIT_ARRAY packing a prior
            // DOM length temp). Omitting it lets FUNCCALL dead-temp release null the slot (#28555).
            OpCode::TYPE_NULLSAFE => [$op->block1, $op->block2, $op->block3],
            OpCode::TYPE_JUMP => [$op->block1],
            default => [],
        };
    }

    /**
     * One-level JUMPIF target scan — enough to drop cond-expression temps without
     * treating distant merge/successor blocks as live (#14103 vs #13955 fcall keep).
     * ?: arms that JUMP to a shared merge must still preserve prefix temps (#14133);
     * nested ?: arms may chain through inner JUMPIF + JUMP blocks (#14260).
     */
    public function scopeSlotReadInDirectJumpTargets(int $slot): bool
    {
        foreach ($this->opCodes as $op) {
            foreach ($this->controlFlowBranchTargets($op) as $target) {
                if (!$target instanceof self) {
                    continue;
                }
                $seen = [];
                if ($this->branchOrJumpMergeReadsScopeSlot($target, $slot, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, true> $seen
     */
    private function branchOrJumpMergeReadsScopeSlot(self $branch, int $slot, array &$seen = []): bool
    {
        $id = spl_object_id($branch);
        if (isset($seen[$id])) {
            return false;
        }
        $seen[$id] = true;

        foreach ($branch->opCodes as $branchOp) {
            if ($this->opCodeReadsScopeSlot($branchOp, $slot)) {
                return true;
            }
            if (
                OpCode::TYPE_JUMP === $branchOp->type
                && $branchOp->block1 instanceof self
                && $this->branchOrJumpMergeReadsScopeSlot($branchOp->block1, $slot, $seen)
            ) {
                return true;
            }
            if (OpCode::TYPE_COALESCE === $branchOp->type) {
                foreach ([$branchOp->block1, $branchOp->block2] as $target) {
                    if (
                        $target instanceof self
                        && $this->branchOrJumpMergeReadsScopeSlot($target, $slot, $seen)
                    ) {
                        return true;
                    }
                }
            }
            if (OpCode::TYPE_JUMPIF === $branchOp->type) {
                foreach ([$branchOp->block1, $branchOp->block2] as $target) {
                    if (
                        $target instanceof self
                        && $this->branchOrJumpMergeReadsScopeSlot($target, $slot, $seen)
                    ) {
                        return true;
                    }
                }
            }
            if (OpCode::TYPE_NULLSAFE === $branchOp->type) {
                foreach ([$branchOp->block1, $branchOp->block2, $branchOp->block3] as $target) {
                    if (
                        $target instanceof self
                        && $this->branchOrJumpMergeReadsScopeSlot($target, $slot, $seen)
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, true> $seen
     */
    private function blockReadsScopeSlotTree(int $slot, array &$seen): bool
    {
        $id = spl_object_id($this);
        if (isset($seen[$id])) {
            return false;
        }
        $seen[$id] = true;
        foreach ($this->opCodes as $op) {
            if ($this->opCodeReadsScopeSlot($op, $slot)) {
                return true;
            }
        }
        foreach ($this->opCodes as $op) {
            foreach ($this->controlFlowBranchTargets($op) as $target) {
                if ($target instanceof self && $target->blockReadsScopeSlotTree($slot, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Yields [variable name, scope slot] pairs. */
    public function eachNamedScopeSlot(): \Generator
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

    /** Rebind hoisted producer/consumer temps after opcode emission (#14467, trim($obj->prop)). */
    public function bindOperandScopeSlot(Operand $operand, int $slot): void
    {
        $this->scope[$operand] = $slot;
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

    /** Bound $this from auto-bound / bindTo closure invoke (issue #5325, Zend zend_closures.c). */
    private static function resolveBoundClosureThis(Frame $frame): ?Variable
    {
        if (null !== $frame->pendingClosureInvoke && null !== $frame->pendingClosureInvoke->boundThis) {
            return $frame->pendingClosureInvoke->boundThis;
        }
        if (null !== $frame->closureCall && null !== $frame->closureCall->boundThis) {
            return $frame->closureCall->boundThis;
        }

        return null;
    }

    public static function findVariableInParentFramesByName(string $name, Frame $frame): ?Variable
    {
        // Limit the walk to the function that owns $frame. Foreach/merge blocks set
        // inheritUndefinedLocals (so blocksScriptGlobalInheritance() is false) and must
        // still share locals across CFG edges — but must not alias caller CVs of the same
        // name. That alias made foreach-by-ref over &...$args bind the loop var to itself
        // via the packed indirect (#21932; Zend/zend_execute.c FE_FETCH_RW).
        $startFunc = null !== $frame->block ? $frame->block->func : null;
        $blockScriptGlobals = null !== $frame->block && $frame->block->blocksScriptGlobalInheritance();
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if (
                null !== $startFunc
                && null !== $f->block
                && (null === $f->block->func || $f->block->func !== $startFunc)
            ) {
                break;
            }
            if (
                $blockScriptGlobals
                && null !== $f->block
                && $f->block->isMainScript()
            ) {
                break;
            }
            if ('this' === $name) {
                $boundThis = self::resolveBoundClosureThis($f);
                if (null !== $boundThis) {
                    return $boundThis;
                }
            }
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
                if (isset($this->constants[$pos])) {
                    $scope[$pos] = $this->scopeConstantVariable($pos);
                }

                continue;
            }
            if (null !== $frame && 'this' === self::resolveVariableName($op)) {
                // Entering a closure from another function must not treat the caller's
                // method calledArgs[0] as $this (Zend zend_closures.c; #23704). Only
                // auto-bind / bindTo (pendingClosureInvoke / closureCall) may supply it.
                $enteringFromOtherFunc = null === $this->func
                    || null === $frame->block
                    || null === $frame->block->func
                    || $frame->block->func !== $this->func;
                $isClosureCallee = null !== $this->func
                    && ((($this->func->flags ?? 0) & Func::FLAG_CLOSURE) !== 0);
                if ($enteringFromOtherFunc && $isClosureCallee) {
                    $isStaticClosure = ((($this->func->flags ?? 0) & Func::FLAG_STATIC) !== 0);
                    $boundThis = $isStaticClosure ? null : self::resolveBoundClosureThis($frame);
                    if (null !== $boundThis) {
                        $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
                        if (VM\EnumCaseSupport::isEnumCaseVariable($boundThis)) {
                            $boundThis = VM\EnumCaseSupport::materializeConstantValue($context, $boundThis);
                        }
                        $scope[$pos]->copyFrom($boundThis);
                        continue;
                    }
                    $scope[$pos] = new Variable(Variable::TYPE_UNDEFINED);
                    continue;
                }
                // Include/eval {main} must not treat caller calledArgs[0] as $this —
                // that is a function's first user argument. TYPE_EVAL / TYPE_INCLUDE copy
                // EX(This) after getFrame (ZEND_INCLUDE_OR_EVAL, #31902 / #31903).
                if ($enteringFromOtherFunc && $this->isMainScript()) {
                    $scope[$pos] = new Variable(Variable::TYPE_UNDEFINED);
                    continue;
                }
                if (!empty($frame->callArgs)) {
                    $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
                    $scope[$pos]->copyFrom($frame->callArgs[0]);
                    continue;
                }
                if (!empty($frame->calledArgs)) {
                    $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
                    $scope[$pos]->copyFrom($frame->calledArgs[0]);
                    continue;
                }
                $boundThis = self::resolveBoundClosureThis($frame);
                if (null !== $boundThis) {
                    $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
                    if (VM\EnumCaseSupport::isEnumCaseVariable($boundThis)) {
                        $boundThis = VM\EnumCaseSupport::materializeConstantValue($context, $boundThis);
                    }
                    $scope[$pos]->copyFrom($boundThis);
                    continue;
                }
                // Static method references $this; frame setup must succeed so VM/JIT raise Error (#5261).
                if ($this->isStaticMethodBlock()) {
                    $scope[$pos] = new Variable(Variable::TYPE_UNDEFINED);
                    continue;
                }
            }

            if (isset($this->constants[$pos]) && !$this->args->contains($op)) {
                $scope[$pos] = $this->scopeConstantVariable($pos);
            } elseif (isset($this->closureCaptureSlots[$pos])) {
                $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
            } elseif ($this->isArgRecvParameterSlot($pos)) {
                // Params are not in $args (compileOperand isRead=false); still need type metadata (#7057).
                $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
            } elseif ($this->args->contains($op)) {
                // Callee parameters are filled by TYPE_ARG_RECV; do not inherit caller locals (#3803).
                if (is_null($frame)) {
                    $scope[$pos] = self::initialEntryVariable($op, $context, $pos, $this);
                    continue;
                }
                $found = false;
                // Resolve reads from the jump parent block, not the merge block's scope (#3787).
                if (!$this->blocksScriptGlobalInheritance()) {
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
                        if (self::usesMainScriptGlobalSlot($op, $this)) {
                            $scope[$pos] = self::initialEntryVariable($op, $context, $pos, $this);
                            continue;
                        }
                        $scope[$pos] = new Variable(Variable::TYPE_UNDEFINED);
                        continue;
                    }
                    if (null !== $name && 'this' === $name && $this->isStaticMethodBlock()) {
                        $scope[$pos] = new Variable(Variable::TYPE_UNDEFINED);
                        continue;
                    }
                    if ($this->blocksScriptGlobalInheritance()) {
                        $scope[$pos] = new Variable(Variable::TYPE_UNDEFINED);
                        continue;
                    }
                    throw new \LogicException("Could not resolve argument");
                }
            } else {
                $name = self::resolveVariableName($op);
                if (null !== $name && Superglobals::isSuperglobalName($name)) {
                    $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
                    continue;
                }
                if (null !== $frame && !$this->blocksScriptGlobalInheritance()) {
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
                    if (null !== $name && $this->declaresGlobalName($name)) {
                        $local = new Variable(Variable::TYPE_NULL);
                        $local->indirect($context->ensureGlobal($name));
                        $scope[$pos] = $local;
                    } elseif (null === $frame || self::usesMainScriptGlobalSlot($op, $this)) {
                        // {main} locals live in the global table on every CFG block (#3601, #3787).
                        $scope[$pos] = self::initialEntryVariable($op, $context, $pos, $this);
                    } elseif ($this->blocksScriptGlobalInheritance() && null !== $frame) {
                        $scope[$pos] = new Variable(Variable::TYPE_UNDEFINED);
                    } else {
                        $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
                    }
                }
            }
        }

        $this->ensureOpcodeReferencedSlots($scope);

        // Sparse slot indices must preserve keys; variadic spread reindexes (#137).
        $return = new Frame(null, $this, $frame);
        $return->scope = $scope;
        // Empty function bodies often lack CFG ops with getFile(); inherit the caller path so
        // user-arg TypeError traces are not `[internal function]` (#29023).
        $return->scriptPath = $this->scriptPath();
        if ('' === $return->scriptPath && null !== $frame && '' !== $frame->scriptPath) {
            $return->scriptPath = $frame->scriptPath;
        }
        if (null !== $frame) {
            if (null !== $frame->returnVar) {
                $return->returnVar = $frame->returnVar;
            }
            // CFG try/catch/merge and other block edges must keep VM context (#21266).
            if (null !== $frame->vmContext) {
                $return->vmContext = $frame->vmContext;
            }
            // CFG branch targets (e.g. function-static init) must keep closure invoke context (#4872).
            if (null !== $frame->closureCall) {
                $return->closureCall = $frame->closureCall;
            }
            // Bound Closure::bind/bindTo scope must survive ?? / if / try CFG edges (#24335).
            // Methods fall back to func->class; top-level bound closures only have calledClass.
            if (null !== $frame->calledClass && '' !== $frame->calledClass) {
                $return->calledClass = $frame->calledClass;
            }
            if (null !== $frame->generatorState) {
                $return->generatorState = $frame->generatorState;
            }
            // Zend CV "initialized" bitmap survives across CFG block frames (#4489, generator_nested.phpt).
            // Do not inherit caller slot-init bits when entering a nested user function (#12421).
            for ($f = $frame; null !== $f; $f = $f->parent) {
                if (
                    null !== $this->func
                    && (null === $f->block->func || $f->block->func !== $this->func)
                ) {
                    break;
                }
                foreach ($f->initializedSlots as $slot => $_) {
                    if (isset($scope[$slot])) {
                        $return->initializedSlots[$slot] = true;
                    }
                }
            }
            // func_get_arg(s)/func_num_args() read calledArgs on the active frame (#12337).
            if ([] !== $frame->calledArgs) {
                $return->calledArgs = $frame->calledArgs;
            }
        }
        return $return;
    }

    /**
     * Opcodes may reference slot indices without a matching scope operand (#5911, enum ctor assign).
     */
    private function ensureOpcodeReferencedSlots(array &$scope): void
    {
        $max = -1;
        foreach ($this->opCodes as $op) {
            foreach (['arg1', 'arg2', 'arg3'] as $field) {
                $idx = $op->$field;
                if (null !== $idx && (int) $idx > $max) {
                    $max = (int) $idx;
                }
            }
        }
        for ($i = 0; $i <= $max; ++$i) {
            if (isset($scope[$i])) {
                if (isset($this->constants[$i])) {
                    $scope[$i] = $this->scopeConstantVariable($i);
                }

                continue;
            }
            if (isset($this->constants[$i])) {
                $scope[$i] = $this->scopeConstantVariable($i);

                continue;
            }
            $scope[$i] = new Variable();
        }
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
            if (isset($block->paramClassConstraints[$slot])) {
                $local->resolveIndirect()->classConstraint = $block->paramClassConstraints[$slot];
            }
            if (isset($block->paramDeclaredTypeLabels[$slot])) {
                $local->resolveIndirect()->declaredTypeLabel = $block->paramDeclaredTypeLabels[$slot];
            }
            if (isset($block->paramLiteralBoolTypes[$slot])) {
                $local->resolveIndirect()->literalBoolType = $block->paramLiteralBoolTypes[$slot];
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
        if (isset($block->paramClassConstraints[$slot])) {
            $var->classConstraint = $block->paramClassConstraints[$slot];
        }
        if (isset($block->paramDeclaredTypeLabels[$slot])) {
            $var->declaredTypeLabel = $block->paramDeclaredTypeLabels[$slot];
        }
        if (isset($block->paramLiteralBoolTypes[$slot])) {
            $var->literalBoolType = $block->paramLiteralBoolTypes[$slot];
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
        foreach ($this->orig->children as $child) {
            if (!$child instanceof Op\Terminal\Return_) {
                continue;
            }
            if (null === $child->expr) {
                continue;
            }
            if ($child->expr === $result) {
                return true;
            }
            if (null !== $resultRoot && self::cfgVarRoot($child->expr) === $resultRoot) {
                return true;
            }
            $expr = $child->expr;
            while ($expr instanceof Temporary && null !== $expr->original) {
                if ($expr->original === $result) {
                    return true;
                }
                if (null !== $resultRoot && self::cfgVarRoot($expr->original) === $resultRoot) {
                    return true;
                }
                $expr = $expr->original;
            }
        }

        return false;
    }

    /**
     * Call result is echoed directly (php-cfg often omits usages on Terminal_Echo expr, #10704).
     */
    public function callResultFeedsEcho(Operand $result): bool
    {
        $resultRoot = self::cfgVarRoot($result);
        foreach ($this->orig->children as $child) {
            if (!$child instanceof Op\Terminal\Echo_) {
                continue;
            }
            if (null === $child->expr) {
                continue;
            }
            foreach (self::echoExprOperands($child->expr) as $expr) {
                if ($this->callResultOperandMatchesConsumer($expr, $result, $resultRoot)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<Operand>
     */
    private static function echoExprOperands(Operand $expr): array
    {
        $concat = self::unwrapConcatListOperand($expr);
        if (null !== $concat) {
            return $concat->list;
        }

        return [$expr];
    }

    private static function unwrapConcatListOperand(Operand $operand): ?Op\Expr\ConcatList
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

    private function callResultOperandMatchesConsumer(Operand $expr, Operand $result, ?Operand $resultRoot): bool
    {
        if ($expr === $result) {
            return true;
        }
        if (null !== $resultRoot && self::cfgVarRoot($expr) === $resultRoot) {
            return true;
        }
        while ($expr instanceof Temporary && null !== $expr->original) {
            if ($expr->original === $result) {
                return true;
            }
            if (null !== $resultRoot && self::cfgVarRoot($expr->original) === $resultRoot) {
                return true;
            }
            $expr = $expr->original;
        }

        return false;
    }

    /**
     * `@` suppress region forwards its inner expression value across END_SILENCE (#10336, #3546).
     *
     * php-cfg {@see ErrorSuppressBlock}: readVariable() on the inner expr does not register FuncCall usages.
     */
    public function callResultFeedsErrorSuppressExit(Operand $result): bool
    {
        if (null === $this->orig || !($this->orig instanceof ErrorSuppressBlock)) {
            return false;
        }
        $resultRoot = self::cfgVarRoot($result);
        if (null === $resultRoot) {
            return false;
        }
        foreach ($this->orig->children as $child) {
            if (
                $child instanceof Op\Expr\FuncCall
                || $child instanceof Op\Expr\NsFuncCall
                || $child instanceof Op\Expr\MethodCall
                || $child instanceof Op\Expr\StaticCall
                || $child instanceof Op\Expr\New_
            ) {
                if (self::cfgVarRoot($child->result) === $resultRoot) {
                    return true;
                }
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

    /**
     * Standalone `true`/`false`/`null` parameter types — exact match even without caller strict_types
     * (Zend zend_check_type / zend_verify_arg_type, issue #7057).
     */
    public function paramRequiresExactLiteralMatch(int $slot): bool
    {
        return isset($this->paramLiteralBoolTypes[$slot])
            || isset($this->paramIterableSlots[$slot])
            || isset($this->paramCallableSlots[$slot])
            || (
                isset($this->paramTypeConstraints[$slot])
                && Variable::TYPE_NULL === $this->paramTypeConstraints[$slot]
                && !isset($this->paramDnfConstraints[$slot])
                && !isset($this->paramIntersectionConstraints[$slot])
            );
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
        $value = $nameOp->value;
        // php-cfg: Expr_Variable name Scalar_LNumber → int Literal for ${1} (Zend CV "$1", #22776).
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (!\is_string($value)) {
            return null;
        }

        return $value;
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

    /**
     * php-cfg match lowering emits one TYPE_IDENTICAL per non-default arm (#143).
     * Detection helper; no longer forces {@see requiresVmLowering} (#4623).
     */
    public static function containsMatchExpressionOpcodesInScriptScope(?self $root): bool
    {
        return self::countOpcodeTypesSkippingFuncDefs($root, OpCode::TYPE_IDENTICAL) >= 2
            && self::containsOpcodeTypesSkippingFuncDefs($root, OpCode::TYPE_JUMPIF);
    }

    /**
     * @param int ...$types OpCode::TYPE_* values to count
     */
    private static function countOpcodeTypesSkippingFuncDefs(?self $root, int ...$types): int
    {
        if (null === $root || [] === $types) {
            return 0;
        }
        $want = array_fill_keys($types, true);
        $count = 0;
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
                    ++$count;
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof self) {
                        if (
                            (OpCode::TYPE_FUNCDEF === $op->type || OpCode::TYPE_DECLARE_METHOD === $op->type)
                            && $sub === $op->block1
                        ) {
                            continue;
                        }
                        $stack[] = $sub;
                    }
                }
            }
        }

        return $count;
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
     * Direct callable body only — nested TYPE_CLOSURE / TYPE_FUNCDEF bodies are separate callables (#10731).
     */
    public static function containsGeneratorOpcodesInCallableBody(?self $root): bool
    {
        return self::containsOpcodeTypesSkippingNestedCallableBodies(
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
     * True when any block invokes a global function by compile-time literal name (#16075).
     *
     * @param string ...$names Lowercase builtin names (e.g. preg_match)
     */
    public static function containsLiteralFuncCalls(?self $root, string ...$names): bool
    {
        if (null === $root || [] === $names) {
            return false;
        }
        $want = array_fill_keys(array_map('strtolower', $names), true);
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    try {
                        $nameOp = $block->getOperand($op->arg1);
                        if ($nameOp instanceof Operand\Literal && is_string($nameOp->value)) {
                            $lc = strtolower($nameOp->value);
                            if (isset($want[$lc])) {
                                return true;
                            }
                        }
                    } catch (\Throwable) {
                    }
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

    /** preg_* calls that require standalone AOT prelink before user-script lowering (#16075). */
    public static function containsPregPrelinkBuiltinCalls(?self $root): bool
    {
        return self::containsLiteralFuncCalls(
            $root,
            'preg_match',
            'preg_match_all',
            'preg_replace',
            'preg_replace_callback',
            'preg_replace_callback_array',
            'preg_split',
            'preg_grep',
            'preg_filter',
            'preg_quote',
            'preg_last_error',
            'preg_last_error_msg',
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
                        if (
                            (OpCode::TYPE_FUNCDEF === $op->type || OpCode::TYPE_DECLARE_METHOD === $op->type)
                            && $sub === $op->block1
                        ) {
                            continue;
                        }
                        $stack[] = $sub;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param int ...$types OpCode::TYPE_* values to match
     */
    private static function containsOpcodeTypesSkippingNestedCallableBodies(?self $root, int ...$types): bool
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
                        if (
                            $sub === $op->block1
                            && in_array($op->type, [
                                OpCode::TYPE_FUNCDEF,
                                OpCode::TYPE_DECLARE_METHOD,
                                OpCode::TYPE_CLOSURE,
                            ], true)
                        ) {
                            continue;
                        }
                        $stack[] = $sub;
                    }
                }
            }
        }

        return false;
    }

    /** Script contains try/catch/finally/throw opcodes (#2114). JIT lowers via TryCatchHelper (#4246). */
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

    /**
     * Top-level script scope only (skips nested TYPE_FUNCDEF bodies; issue #3074).
     * EH inside generator resume functions is lowered via GeneratorHelper + TryCatchHelper.
     */
    public static function containsExceptionHandlingOpcodesInScriptScope(?self $root): bool
    {
        return self::containsOpcodeTypesSkippingFuncDefs(
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

    /** Top-level script scope only (skips nested TYPE_FUNCDEF bodies; issue #3074). */
    public static function containsFinallyOpcodesInScriptScope(?self $root): bool
    {
        return self::containsOpcodeTypesSkippingFuncDefs($root, OpCode::TYPE_FINALLY);
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

    public static function containsDynamicStaticPropertyOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (
                    in_array($op->type, [OpCode::TYPE_STATIC_PROPERTY_FETCH, OpCode::TYPE_STATIC_PROPERTY_UNSET], true)
                    && null !== $op->arg3
                    && !isset($block->constants[$op->arg3])
                ) {
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

    /**
     * Closures with {@code use ($var)} / {@code use (&$var)} — MCJIT IR verify / execute unstable (#72, #2483).
     */
    public static function containsClosureUseCaptureOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_CLOSURE === $op->type && [] !== $op->closureCaptures) {
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

    /**
     * Closures with {@code use (&$var)} — detection helper (#72, #3097, #4625).
     * No longer forces {@see requiresVmLowering}; MCJIT uses {@see Variable::$valueBoxAliasPtr}.
     */
    public static function containsClosureByRefCaptureOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_CLOSURE === $op->type) {
                    foreach ($op->closureCaptures as $capture) {
                        if (!empty($capture['byRef'])) {
                            return true;
                        }
                    }
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

    /**
     * User functions/methods with non-void declared return types — MCJIT execute segfaults (#55, #2055).
     * LLVM IR verify passes ({@see FunctionReturnTypeJitCompileTest}); AOT execute is OK.
     */
    public static function containsTypedNonVoidReturnOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            if (null !== $block->func && self::cfgFuncHasTypedNonVoidReturn($block->func)) {
                return true;
            }
            foreach ($block->opCodes as $op) {
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof self) {
                        $stack[] = $sub;
                    }
                }
            }
        }

        return false;
    }

    private static function cfgFuncHasTypedNonVoidReturn(Func $func): bool
    {
        $returnType = $func->returnType;
        if (null === $returnType) {
            return false;
        }
        if ($returnType instanceof Op\Type\Void_ || $returnType instanceof Op\Type\Never_) {
            return false;
        }
        if ($returnType instanceof Op\Type\Mixed_) {
            // php-cfg uses Mixed_ for *untyped* functions; explicit `: mixed` is Literal.
            return false;
        }
        if ($returnType instanceof Op\Type\Literal) {
            $name = strtolower($returnType->name);

            // Explicit `: mixed` still requires a return value (zend_verify_return_type / #26485).
            return 'void' !== $name && 'never' !== $name;
        }

        return true;
    }

    /**
     * `readonly class` declarations — MCJIT execute for promoted/instance stores segfaults (#4082).
     * Per-property `readonly` uses {@see containsReadonlyPropertyOpcodes}.
     */
    public static function containsReadonlyClassOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (
                    OpCode::TYPE_DECLARE_CLASS === $op->type
                    && null !== $op->arg3
                    && isset($block->constants[$op->arg3])
                    && VM\ClassFlags::isReadonly($block->constants[$op->arg3]->toInt())
                ) {
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

    /**
     * Per-property readonly declarations — MCJIT uncaught violation exits
     * with segfault instead of surfacing pending exception (#3149, #1360).
     */
    public static function containsReadonlyPropertyOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_PROPERTY === $op->type && $op->propertyReadonly) {
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

    /**
     * CFG regions that MCJIT must not execute yet; `bin/jit.php` runs the VM instead (#2114, #167).
     * Simple try/catch without `finally` may pass MCJIT when {@see TryCatchJitExecuteTest} is green.
     */
    /**
     * ReflectionClass::newLazyProxy/Ghost — detection for scripts using lazy objects (#4685, #4940).
     *
     * @see Zend/zend_lazy_objects.c
     */
    public static function containsLazyObjectOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_METHODCALL_INIT === $op->type) {
                    $nameOp = $block->getOperand($op->arg2);
                    if ($nameOp instanceof Literal) {
                        $methodLc = strtolower($nameOp->value);
                        if ('newlazyproxy' === $methodLc || 'newlazyghost' === $methodLc) {
                            return true;
                        }
                    }
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

    /** Script or nested closure uses Fiber::suspend() (#4019). */
    public static function containsFiberSuspendOpcodes(?self $root): bool
    {
        return self::walkFiberSuspendOpcodes($root, false);
    }

    /**
     * Top-level script scope only (skips nested TYPE_FUNCDEF bodies; issue #4097).
     * Fiber callbacks compile to MCJIT resume functions like generator bodies (#3074).
     */
    public static function containsFiberSuspendOpcodesInScriptScope(?self $root): bool
    {
        return self::walkFiberSuspendOpcodes($root, true);
    }

    private static function walkFiberSuspendOpcodes(?self $root, bool $skipFuncDefs): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_STATICCALL_INIT === $op->type) {
                    $classOp = $block->getOperand($op->arg1);
                    $nameOp = $block->getOperand($op->arg2);
                    if (
                        $classOp instanceof Literal
                        && $nameOp instanceof Literal
                        && 0 === strcasecmp(ltrim($classOp->value, '\\'), 'Fiber')
                        && 0 === strcasecmp($nameOp->value, 'suspend')
                    ) {
                        return true;
                    }
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof self) {
                        if ($skipFuncDefs && $sub === $op->block1) {
                            if (
                                OpCode::TYPE_FUNCDEF === $op->type
                                || OpCode::TYPE_DECLARE_METHOD === $op->type
                                || OpCode::TYPE_CLOSURE === $op->type
                            ) {
                                continue;
                            }
                        }
                        $stack[] = $sub;
                    }
                }
            }
        }

        return false;
    }

    /**
     * User-defined classes with declared instance properties (diagnostics / predefine pass).
     * Vendor/compiler classes (phpcfg/phpcompiler) are excluded from the scan.
     */
    public static function containsUserClassDeclaredInstancePropertyOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_CLASS === $op->type && $op->block1 instanceof self) {
                    $nameOp = $block->getOperand($op->arg1);
                    if ($nameOp instanceof Literal) {
                        $classLc = strtolower(ltrim($nameOp->value, '\\'));
                        if (
                            !str_starts_with($classLc, 'phpcfg\\')
                            && !str_starts_with($classLc, 'phpcompiler\\')
                            && self::classBodyDeclaresInstanceProperty($op->block1)
                        ) {
                            return true;
                        }
                    }
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

    private static function classBodyDeclaresInstanceProperty(self $classBlock): bool
    {
        $seen = new \SplObjectStorage();
        $stack = [$classBlock];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (
                    OpCode::TYPE_DECLARE_PROPERTY === $op->type
                    && !$op->propertyFromConstructorPromotion
                ) {
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

    /**
     * Writes to undeclared instance properties on classes without #[\AllowDynamicProperties].
     * Detects undeclared writes for diagnostics; JIT emits E_DEPRECATED via DynamicPropertyDeprecationGuard (#4570, #5111).
     */
    public static function containsDynamicPropertyDeprecationOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        /** @var array<string, array<string, true>> $declaredProps lc class => lc prop => true */
        $declaredProps = [];
        /** @var array<string, true> $allowsDynamic lc class => true */
        $allowsDynamic = [];
        /** @var array<string, true> $hasMagicSet lc class => true */
        $hasMagicSet = [];
        self::collectDynamicPropertyClassMetadata($root, $declaredProps, $allowsDynamic, $hasMagicSet);

        return self::scanUndeclaredInstancePropertyWrites($root, $declaredProps, $allowsDynamic, $hasMagicSet);
    }

    /**
     * @param array<string, array<string, true>> $declaredProps
     * @param array<string, true>               $allowsDynamic
     * @param array<string, true>               $hasMagicSet
     */
    private static function collectDynamicPropertyClassMetadata(
        ?self $root,
        array &$declaredProps,
        array &$allowsDynamic,
        array &$hasMagicSet
    ): void {
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_CLASS === $op->type) {
                    $nameOp = $block->getOperand($op->arg1);
                    if ($nameOp instanceof Literal) {
                        $classLc = strtolower(ltrim($nameOp->value, '\\'));
                        $declaredProps[$classLc] = $declaredProps[$classLc] ?? [];
                        if (AttributeNames::hasAllowDynamicProperties($op->attributeNames)) {
                            $allowsDynamic[$classLc] = true;
                        }
                        if ($op->block1 instanceof self) {
                            self::collectDeclaredPropertiesFromClassBody(
                                $op->block1,
                                $classLc,
                                $declaredProps,
                                $hasMagicSet
                            );
                        }
                    }
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof self) {
                        $stack[] = $sub;
                    }
                }
            }
        }
    }

    /**
     * @param array<string, array<string, true>> $declaredProps
     * @param array<string, true>               $hasMagicSet
     */
    private static function collectDeclaredPropertiesFromClassBody(
        self $classBlock,
        string $classLc,
        array &$declaredProps,
        array &$hasMagicSet
    ): void {
        $seen = new \SplObjectStorage();
        $stack = [$classBlock];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_PROPERTY === $op->type) {
                    $propOp = $block->getOperand($op->arg1);
                    if ($propOp instanceof Literal) {
                        $declaredProps[$classLc][strtolower($propOp->value)] = true;
                    }
                }
                if (OpCode::TYPE_DECLARE_METHOD === $op->type) {
                    $methodOp = $block->getOperand($op->arg1);
                    if ($methodOp instanceof Literal && '__set' === strtolower($methodOp->value)) {
                        $hasMagicSet[$classLc] = true;
                    }
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof self) {
                        $stack[] = $sub;
                    }
                }
            }
        }
    }

    /**
     * @param array<string, array<string, true>> $declaredProps
     * @param array<string, true>               $allowsDynamic
     * @param array<string, true>               $hasMagicSet
     */
    private static function scanUndeclaredInstancePropertyWrites(
        ?self $root,
        array $declaredProps,
        array $allowsDynamic,
        array $hasMagicSet
    ): bool {
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $i => $op) {
                if (!in_array($op->type, [OpCode::TYPE_PROPERTY_FETCH, OpCode::TYPE_PROPERTY_FETCH_WRITE], true)) {
                    foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                        if ($sub instanceof self) {
                            $stack[] = $sub;
                        }
                    }
                    continue;
                }
                $nameOp = $block->getOperand($op->arg3);
                $objOp = $block->getOperand($op->arg2);
                if (!$nameOp instanceof Literal || null === $objOp->type || null === $objOp->type->userType) {
                    foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                        if ($sub instanceof self) {
                            $stack[] = $sub;
                        }
                    }
                    continue;
                }
                if (!self::propertyFetchDestUsedAsAssignLvalue($block, $i, (int) $op->arg1)) {
                    foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                        if ($sub instanceof self) {
                            $stack[] = $sub;
                        }
                    }
                    continue;
                }
                $classLc = strtolower(ltrim($objOp->type->userType, '\\'));
                if (isset($allowsDynamic[$classLc]) || isset($hasMagicSet[$classLc])) {
                    foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                        if ($sub instanceof self) {
                            $stack[] = $sub;
                        }
                    }
                    continue;
                }
                $propLc = strtolower($nameOp->value);
                if (!isset($declaredProps[$classLc][$propLc])) {
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

    /**
     * Undeclared instance property writes keyed by lc class name (JIT predefine after compileClass, #5111).
     *
     * @return array<string, list<string>>
     */
    public static function collectJitUndeclaredInstancePropertyWrites(?self $root): array
    {
        if (null === $root) {
            return [];
        }
        $declaredProps = [];
        $allowsDynamic = [];
        $hasMagicSet = [];
        self::collectDynamicPropertyClassMetadata($root, $declaredProps, $allowsDynamic, $hasMagicSet);

        /** @var array<string, list<string>> $pending */
        $pending = [];
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $i => $op) {
                if (!in_array($op->type, [OpCode::TYPE_PROPERTY_FETCH, OpCode::TYPE_PROPERTY_FETCH_WRITE], true)) {
                    foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                        if ($sub instanceof self) {
                            $stack[] = $sub;
                        }
                    }
                    continue;
                }
                if (null === $op->arg3 || null === $op->arg2) {
                    foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                        if ($sub instanceof self) {
                            $stack[] = $sub;
                        }
                    }
                    continue;
                }
                $nameOp = $block->getOperand($op->arg3);
                $objOp = $block->getOperand($op->arg2);
                if (!$nameOp instanceof Literal || null === $objOp->type || null === $objOp->type->userType) {
                    foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                        if ($sub instanceof self) {
                            $stack[] = $sub;
                        }
                    }
                    continue;
                }
                if (!self::propertyFetchDestUsedAsAssignLvalue($block, $i, (int) $op->arg1)) {
                    foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                        if ($sub instanceof self) {
                            $stack[] = $sub;
                        }
                    }
                    continue;
                }
                $classLc = strtolower(ltrim($objOp->type->userType, '\\'));
                if (isset($allowsDynamic[$classLc]) || isset($hasMagicSet[$classLc])) {
                    foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                        if ($sub instanceof self) {
                            $stack[] = $sub;
                        }
                    }
                    continue;
                }
                $propLc = strtolower($nameOp->value);
                if (!isset($declaredProps[$classLc][$propLc])) {
                    $pending[$classLc] ??= [];
                    if (!in_array($nameOp->value, $pending[$classLc], true)) {
                        $pending[$classLc][] = $nameOp->value;
                    }
                    $declaredProps[$classLc][$propLc] = true;
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof self) {
                        $stack[] = $sub;
                    }
                }
            }
        }

        return $pending;
    }

    private static function propertyFetchDestUsedAsAssignLvalue(self $block, int $opIndex, int $destSlot): bool
    {
        // Immediate next only — later ASSIGN is often dead-temp reuse, not a write (#23986).
        $next = $block->opCodes[$opIndex + 1] ?? null;
        if (null === $next) {
            return false;
        }

        return OpCode::destSlotUsedAsAssignLvalue($next, $destSlot);
    }

    /**
     * Empty trait bodies (`trait T {}`) — MCJIT LLVM verify fails (#6284); VM fallback until fixed.
     *
     * Traits with methods or properties (including promoted `__construct`, #4939) lower via MCJIT.
     */
    public static function containsDeclareTraitOpcodesInScriptScope(?self $root): bool
    {
        return self::containsEmptyTraitBodyMcjitDeferral($root);
    }

    public static function containsEmptyTraitBodyMcjitDeferral(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_TRAIT === $op->type
                    && $op->block1 instanceof self
                    && !self::traitBodyHasMembers($op->block1)) {
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

    private static function traitBodyHasMembers(self $body): bool
    {
        $seen = new \SplObjectStorage();
        $stack = [$body];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_METHOD === $op->type
                    || OpCode::TYPE_DECLARE_PROPERTY === $op->type
                    || OpCode::TYPE_DECLARE_STATIC_PROPERTY === $op->type
                    || OpCode::TYPE_DECLARE_CLASS_CONST === $op->type) {
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

    /**
     * Trait `__construct` merged into a using class (#4671, #4939). Detection only; no longer VM gate.
     */
    public static function containsTraitConstructorOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_TRAIT === $op->type && null !== $op->block1) {
                    if (self::classBodyDeclaresMethod($op->block1, '__construct')) {
                        return true;
                    }
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

    private static function classBodyDeclaresMethod(self $body, string $methodLc): bool
    {
        $seen = new \SplObjectStorage();
        $stack = [$body];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_METHOD === $op->type) {
                    $name = $block->getOperand($op->arg1);
                    if ($name instanceof Literal && $methodLc === strtolower($name->value)) {
                        return true;
                    }
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

    /**
     * Global {@code const NAME = [...]} literals (MCJIT uses module globals — #4904, #4941).
     */
    public static function containsGlobalConstArrayLiteralOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_GLOBAL_CONST === $op->type
                    && isset($block->constants[$op->arg2])
                    && Variable::TYPE_ARRAY === $block->constants[$op->arg2]->type) {
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

    /**
     * Interface abstract static + static call: MCJIT execute segfault (#5090); VM matches Zend.
     */
    public static function containsInterfaceAbstractStaticMcjitDeferral(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $hasAbstractIfaceStatic = false;
        $hasStaticCall = false;
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_STATICCALL_INIT === $op->type) {
                    $hasStaticCall = true;
                }
                if (OpCode::TYPE_DECLARE_INTERFACE === $op->type && $op->block1 instanceof self) {
                    foreach ($op->block1->opCodes as $memberOp) {
                        if (OpCode::TYPE_DECLARE_METHOD !== $memberOp->type || null !== $memberOp->block1) {
                            continue;
                        }
                        $visFlags = Func::FLAG_PUBLIC;
                        if (null !== $memberOp->arg3 && isset($op->block1->constants[$memberOp->arg3])) {
                            $visFlags = $op->block1->constants[$memberOp->arg3]->toInt();
                        }
                        if (($visFlags & Func::FLAG_STATIC) !== 0) {
                            $hasAbstractIfaceStatic = true;
                        }
                    }
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof self) {
                        $stack[] = $sub;
                    }
                }
            }
        }

        return $hasAbstractIfaceStatic && $hasStaticCall;
    }

    /**
     * ReflectionAttribute::newInstance() MCJIT execute segfaults (#4598); VM path matches Zend.
     */
    /**
     * eval() that cannot use the JIT literal-inline path must run on VM (#10248, #25535).
     *
     * - Non-literal `eval($var)` — runtime compile only on VM.
     * - Literal eval that declares class/interface/trait/enum/function — IncludeHelper
     *   inline of those decls segfaults under MCJIT; VM TYPE_EVAL raises Zend-shaped
     *   CompileFatal (e.g. final plain properties on the reference profile).
     */
    public static function containsNonLiteralEvalOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_EVAL === $op->type) {
                    $codeOp = $block->getOperand($op->arg2);
                    if (!$codeOp instanceof Operand\Literal) {
                        return true;
                    }
                    if (self::literalEvalSourceNeedsVm((string) $codeOp->value)) {
                        return true;
                    }
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

    /**
     * True when a compile-time eval string declares a type/function — unsafe to MCJIT-inline.
     *
     * php-src: zif_eval always compiles at runtime; we only inline expression-only literals.
     */
    public static function literalEvalSourceNeedsVm(string $source): bool
    {
        return 1 === preg_match(
            '/\b(?:class|interface|trait|enum|function)\b/i',
            $source
        );
    }

    /** func_get_arg(s) / func_num_args() — CallArgv must be stored at each call site (#197, #15907). */
    public static function usesFuncArgsIntrospection(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    $nameOp = $block->getOperand($op->arg1);
                    if ($nameOp instanceof Operand\Literal) {
                        $lc = strtolower($nameOp->value);
                        if (
                            'func_get_args' === $lc
                            || 'func_get_arg' === $lc
                            || 'func_num_args' === $lc
                        ) {
                            return true;
                        }
                    }
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

    public static function containsReflectionAttributeNewInstanceOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_METHODCALL_INIT === $op->type) {
                    $nameOp = $block->getOperand($op->arg2);
                    if ($nameOp instanceof Operand\Literal && 'newinstance' === strtolower($nameOp->value)) {
                        return true;
                    }
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

    public static function requiresVmLowering(?self $root): bool
    {
        return self::containsGeneratorOpcodesInScriptScope($root)
            || self::containsFinallyOpcodesInScriptScope($root)
            || self::containsTypedNonVoidReturnOpcodes($root)
            || self::containsReadonlyPropertyOpcodes($root)
            || self::containsUserClassDeclaredInstancePropertyOpcodes($root)
            || self::containsDynamicPropertyDeprecationOpcodes($root)
            || self::containsFiberSuspendOpcodesInScriptScope($root)
            || self::containsEmptyTraitBodyMcjitDeferral($root)
            || self::containsReflectionAttributeNewInstanceOpcodes($root)
            || self::containsNonLiteralEvalOpcodes($root)
            || self::containsInterfaceAbstractStaticMcjitDeferral($root)
            || self::containsNonStaticStaticCallOpcodes($root)
            || self::containsParamRuntimeNewDefaultOpcodes($root);
    }

    /** Constructor/function parameters with `new` default expressions (#6652). */
    public static function containsParamRuntimeNewDefaultOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            if ([] !== $block->paramRuntimeDefaultInitBlocks) {
                return true;
            }
            foreach ($block->opCodes as $op) {
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof self) {
                        $stack[] = $sub;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Static call to an instance method: MCJIT lacks zend_std_get_static_method guard (#5339).
     */
    public static function containsNonStaticStaticCallOpcodes(?self $root): bool
    {
        if (null === $root) {
            return false;
        }
        /** @var array<string, array<string, bool>> $methodIsStatic */
        $methodIsStatic = [];
        /** @var array<string, string|null> $parents */
        $parents = [];
        self::collectNonStaticStaticCallClassMetadata($root, $methodIsStatic, $parents);

        return self::scanNonStaticStaticCallSites($root, $methodIsStatic, $parents);
    }

    /**
     * @param array<string, array<string, bool>> $methodIsStatic
     * @param array<string, string|null>         $parents
     */
    private static function collectNonStaticStaticCallClassMetadata(
        ?self $root,
        array &$methodIsStatic,
        array &$parents
    ): void {
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_CLASS === $op->type) {
                    $className = self::literalStringFromOperand($block, $op->arg1);
                    if (null !== $className) {
                        $classLc = strtolower(ltrim($className, '\\'));
                        $parentName = self::literalStringFromOperand($block, $op->arg2);
                        $parents[$classLc] = null !== $parentName
                            ? strtolower(ltrim($parentName, '\\'))
                            : null;
                        if ($op->block1 instanceof self) {
                            self::collectMethodStaticFlagsFromClassBody($op->block1, $classLc, $methodIsStatic);
                        }
                    }
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof self) {
                        $stack[] = $sub;
                    }
                }
            }
        }
    }

    /**
     * @param array<string, array<string, bool>> $methodIsStatic
     */
    private static function collectMethodStaticFlagsFromClassBody(
        self $body,
        string $classLc,
        array &$methodIsStatic
    ): void {
        $seen = new \SplObjectStorage();
        $stack = [$body];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_METHOD === $op->type) {
                    $name = $block->getOperand($op->arg1);
                    if ($name instanceof Literal) {
                        $methodLc = strtolower($name->value);
                        $isStatic = false;
                        if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                            $isStatic = 0 !== ($block->constants[$op->arg3]->toInt() & Func::FLAG_STATIC);
                        }
                        if (!$isStatic && null !== $op->block1 && null !== $op->block1->func) {
                            $isStatic = 0 !== (($op->block1->func->flags ?? 0) & Func::FLAG_STATIC);
                        }
                        $methodIsStatic[$classLc][$methodLc] = $isStatic;
                    }
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof self) {
                        $stack[] = $sub;
                    }
                }
            }
        }
    }

    /**
     * @param array<string, array<string, bool>> $methodIsStatic
     * @param array<string, string|null>         $parents
     */
    private static function scanNonStaticStaticCallSites(
        ?self $root,
        array $methodIsStatic,
        array $parents
    ): bool {
        if (null === $root) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof self || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_STATICCALL_INIT === $op->type) {
                    $classOp = $block->getOperand($op->arg1);
                    $nameOp = $block->getOperand($op->arg2);
                    if ($classOp instanceof Literal && $nameOp instanceof Literal) {
                        $calledLc = strtolower(ltrim($classOp->value, '\\'));
                        $methodLc = strtolower($nameOp->value);
                        $isStatic = self::resolveMethodStaticInHierarchy(
                            $methodIsStatic,
                            $parents,
                            $calledLc,
                            $methodLc
                        );
                        if (false === $isStatic) {
                            return true;
                        }
                    }
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

    /**
     * @param array<string, array<string, bool>> $methodIsStatic
     * @param array<string, string|null>         $parents
     */
    private static function resolveMethodStaticInHierarchy(
        array $methodIsStatic,
        array $parents,
        string $calledClassLc,
        string $methodLc
    ): ?bool {
        $current = $calledClassLc;
        $visited = [];
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            if (isset($methodIsStatic[$current][$methodLc])) {
                return $methodIsStatic[$current][$methodLc];
            }
            $current = $parents[$current] ?? null;
            if (null === $current) {
                break;
            }
        }

        return null;
    }

    private static function literalStringFromOperand(self $block, ?int $operandIdx): ?string
    {
        if (null === $operandIdx) {
            return null;
        }
        $operand = $block->getOperand($operandIdx);
        if ($operand instanceof Literal && is_string($operand->value)) {
            return $operand->value;
        }
        if (isset($block->constants[$operandIdx])) {
            return $block->constants[$operandIdx]->toString();
        }

        return null;
    }

    private function isStaticMethodBlock(): bool
    {
        return null !== $this->func
            && null !== $this->func->class
            && (($this->func->flags ?? 0) & Func::FLAG_STATIC) !== 0;
    }
}
