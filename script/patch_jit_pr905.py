#!/usr/bin/env python3
from pathlib import Path
import re

p = Path(__file__).resolve().parents[1] / 'lib/JIT.php'
s = p.read_text()
if 'findcoalesce' in s:
    print('already patched')
    raise SystemExit(0)

replacements = [
(
"            if ($this->isSkippedVmHotPathName($name) || $this->isSkippedCompilerHotPathName($name)) {",
"""            if (
                $this->isSkippedVmHotPathName($name)
                || $this->isSkippedCompilerHotPathName($name)
                || $this->isSkippedWebBootstrapHotPathName($name)
                || $this->isSkippedSelfHostEntryName($name)
            ) {""",
),
(
"        if ($this->isSkippedCompilerHotPathName($logicalName ?? $internalName)) {",
"""        if ($this->isSkippedCompilerHotPathName($logicalName ?? $internalName)
            || $this->isSkippedSelfHostEntryName($logicalName ?? $internalName)
        ) {""",
),
(
"        return str_ends_with($lower, '::runframes') || str_ends_with($lower, '::defineclass');",
"""        return str_ends_with($lower, '::runframes') || str_ends_with($lower, '::defineclass')
            || str_ends_with($lower, '::raise');""",
),
(
"            || str_contains($lower, 'compilefunction')\n            || str_contains($lower, 'compileglobalconst')",
"""            || str_contains($lower, 'compilefunction')
            || str_contains($lower, 'compilefunc')
            || str_contains($lower, 'compileglobalconst')""",
),
(
"            || str_contains($lower, 'compileexpr')\n            || str_contains($lower, 'compileissetmulti')",
"""            || str_contains($lower, 'compileexpr')
            || str_contains($lower, 'getopcodetype')
            || str_contains($lower, 'compileissetmulti')
            || str_contains($lower, 'compileisset')""",
),
]
for old, new in replacements:
    if old not in s:
        raise SystemExit(f'missing: {old[:50]}')
    s = s.replace(old, new, 1)

old = """            || str_contains($lower, 'isarraydim')
            || str_contains($lower, 'resolve');
    }

    /** Stub VM hot-path methods whose opcode switches crash LLVM 9 during self-host AOT (#816). */"""

new = """            || str_contains($lower, 'isarraydim')
            || str_contains($lower, 'findcoalesce')
            || str_contains($lower, 'resolvecoalesce')
            || str_contains($lower, 'resolve');
    }

    private function isSkippedSelfHostEntryName(string $name): bool
    {
        $lower = strtolower($name);

        return str_ends_with($lower, '\\\\runtime::compilefunc')
            || str_ends_with($lower, '\\\\runtime::compile')
            || str_ends_with($lower, '\\\\compiler::compilefunc')
            || str_ends_with($lower, '\\\\compiler::compile');
    }

    private function isSkippedWebBootstrapHotPathName(string $name): bool
    {
        $lower = strtolower($name);

        return str_contains($lower, 'conststringfolder')
            || str_contains($lower, 'includepathresolver')
            || str_contains($lower, 'literalincludediscovery');
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
            if (empty($param->result->usages)) {
                assert($param->declaredType instanceof Op\\Type\\Literal);
                $rawType = Type::fromDecl($param->declaredType->name);
            } else {
                $rawType = $param->result->type;
            }
            $args[] = $this->context->getTypeFromType($rawType);
        }

        return $args;
    }

    /** Stub VM hot-path methods whose opcode switches crash LLVM 9 during self-host AOT (#816). */"""

if old not in s:
    raise SystemExit('missing isarraydim block')
s = s.replace(old, new, 1)

vm_old = """        $args = [];
        $callbackType = 'void';
        if (null !== $block->func) {
            if ($block->func->returnType instanceof Op\\Type\\Void_) {
                $callbackType = 'void';
            } elseif ($block->func->returnType instanceof Op\\Type\\Literal) {
                $callbackType = match ($block->func->returnType->name) {
                    'void' => 'void',
                    'int' => 'long long',
                    default => '__value__',
                };
            } else {
                $callbackType = '__value__';
            }
            if ($this->instanceMethodUsesThis($block)) {
                $args[] = $this->context->getTypeFromString('__object__*');
            }
            foreach ($block->func->params as $param) {
                if (empty($param->result->usages)) {
                    assert($param->declaredType instanceof Op\\Type\\Literal);
                    $rawType = Type::fromDecl($param->declaredType->name);
                } else {
                    $rawType = $param->result->type;
                }
                $args[] = $this->context->getTypeFromType($rawType);
            }
        }
        $returnType = $this->context->getTypeFromString($callbackType);
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($returnType, false, ...$args)
        );
        $bb = $func->appendBasicBlock('stub');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        if ('void' === $callbackType) {
            $this->context->builder->returnVoid();
        } elseif ('long long' === $callbackType) {
            $this->context->builder->returnValue(
                $this->context->getTypeFromString('int64')->constInt(VM::SUCCESS, false)
            );
        } else {
            $this->context->builder->returnValue(
                $this->context->getTypeFromString('__object__*')->constNull()
            );
        }"""

vm_new = """        $args = $this->collectStubFunctionArgTypes($block);
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
        $this->emitSelfHostStubReturn($callbackType, $func, VM::SUCCESS);"""

if vm_old not in s:
    raise SystemExit('missing vm stub block')
s = s.replace(vm_old, vm_new, 1)

s = re.sub(
    r"(private function compileSkippedCompilerSplitCfgStub\(string \$internalName, Block \$block, string \$logicalName\): PHPLLVM\\Value\n    \{.*?\n        \$args = \[\];\n        \$callbackType = '__object__\*';.*?else \{\n            \$this->context->builder->returnValue\(\n                \$this->context->getTypeFromString\('__object__\*'\)->constNull\(\)\n            \);\n        \})",
    lambda m: m.group(0).split("$args = []")[0]
    + "$args = $this->collectStubFunctionArgTypes($block);\n        $callbackType = $this->cfgFunctionReturnCallbackType($block->func) ?? '__object__*';\n        $returnType = $this->context->getTypeFromString($callbackType);\n        $func = $this->context->module->addFunction(\n            $this->llvmInternalName($internalName),\n            $this->context->context->functionType($returnType, false, ...$args)\n        );\n        $bb = $func->appendBasicBlock('stub');\n        $saved = $this->context->builder;\n        $this->context->builder = $this->context->context->builderCreate();\n        $this->context->builder->positionAtEnd($bb);\n        $this->emitSelfHostStubReturn($callbackType, $func);",
    s,
    count=1,
    flags=re.S,
)

helpers_old = """    private function defaultLlvmReturnValue(PHPLLVM\\Value $func): PHPLLVM\\Value
    {
        $fnType = $func->typeOf();
        if (!$fnType instanceof \\PHPLLVM\\Type\\Function_) {
            return $this->context->constantFromInteger(0);
        }
        $returnType = $fnType->getReturnType();
        switch ($this->context->getStringFromType($returnType)) {
            case 'long long':
            case 'int64':
                return $this->context->constantFromInteger(0);
            case 'bool':
            case 'int1':
                return $returnType->constInt(0, false);
            case '__string__*':
                return $returnType->constNull();
            case '__object__*':
            case '__hashtable__*':
                return $returnType->constNull();
            default:
                return $this->context->constantFromInteger(0);
        }
    }"""

helpers_new = """    private function defaultLlvmReturnValue(PHPLLVM\\Value $func): PHPLLVM\\Value
    {
        $fnType = $func->typeOf();
        if (!$fnType instanceof \\PHPLLVM\\Type\\Function_) {
            return $this->context->constantFromInteger(0);
        }

        return $this->defaultLlvmReturnValueForCallbackType(
            $this->context->getStringFromType($fnType->getReturnType()),
            $func
        );
    }

    private function emitSelfHostStubReturn(string $callbackType, PHPLLVM\\Value $func, ?int $longReturn = null): void
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
        PHPLLVM\\Value $func,
        ?int $longReturn = null
    ): PHPLLVM\\Value {
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
                $slot = JIT\\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull'),
                    JIT\\JitValueBox::pointer($this->context, $slot)
                );

                return $this->context->builder->load($slot);
            default:
                $fnType = $func->typeOf();
                if ($fnType instanceof \\PHPLLVM\\Type\\Function_) {
                    $returnType = $fnType->getReturnType();
                    if (\\PHPLLVM\\Type::KIND_POINTER === $returnType->getKind()) {
                        return $returnType->constNull();
                    }
                    if (\\PHPLLVM\\Type::KIND_INTEGER === $returnType->getKind()) {
                        return $returnType->constInt(0, false);
                    }
                }

                return $this->context->constantFromInteger(0);
        }
    }"""

if helpers_old not in s:
    raise SystemExit('missing helpers block')
s = s.replace(helpers_old, helpers_new, 1)
p.write_text(s)
print('patched OK')
