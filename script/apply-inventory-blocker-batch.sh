#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
python3 <<'PY'
from pathlib import Path

def must_replace(src, old, new, label):
    if old not in src:
        raise SystemExit(f'missing {label}')
    return src.replace(old, new, 1)

# Compiler.php
p = Path('lib/Compiler.php')
src = p.read_text()
if 'AssignRef::class' not in src:
    src = must_replace(src,
        "                    } elseif ($child instanceof Op\\Expr\\NullsafePropertyFetch) {\n                        $block = $this->compileNullsafePropertyFetch($child, $block);\n                    } elseif (",
        "                    } elseif ($child instanceof Op\\Expr\\NullsafePropertyFetch) {\n                        $block = $this->compileNullsafePropertyFetch($child, $block);\n                    } elseif ($child instanceof Op\\Expr\\NullsafeMethodCall) {\n                        $block = $this->compileNullsafeMethodCall($child, $block);\n                    } elseif (",
        'compileOps')
    src = must_replace(src,
        "            case Op\\Expr\\InstanceOf_::class:\n                return $this->compileInstanceOf($expr, $block);\n        }\n        throw new \\LogicException(\"Unsupported expression: \" . $expr->getType());",
        """            case Op\\Expr\\InstanceOf_::class:
                return $this->compileInstanceOf($expr, $block);
            case Op\\Expr\\AssignRef::class:
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
        }
        throw new \\LogicException(\"Unsupported expression: \" . $expr->getType());""",
        'AssignRef')
    src = must_replace(src,
        """            default:
                throw new \\LogicException(\"Unknown Terminal Type: \" . $terminal->getType());""",
        """            case 'Terminal_GlobalVar':
                $globalName = $this->resolveSimpleVariableName($terminal->var);
                $nameVar = new Variable(Variable::TYPE_STRING);
                $nameVar->string($globalName);
                $nameOperand = new Operand\\Literal($globalName);
                $nameOperand->type = Type::string();
                $nameSlot = $block->registerConstant($nameOperand, $nameVar);
                return [new OpCode(
                    OpCode::TYPE_DECLARE_GLOBAL,
                    $this->compileOperand($terminal->var, $block, false),
                    $nameSlot
                )];
            default:
                throw new \\LogicException(\"Unknown Terminal Type: \" . $terminal->getType());""",
        'GlobalVar')
    anchor = "    /**\n     * @return ?array{0: int, 1: ?int}\n     */\n    protected function resolveCoalesceIssetTarget"
    if 'function compileNullsafeMethodCall' not in src:
        helper = Path('script/inventory-blocker-compiler-helper.php-snippet').read_text()
        src = src.replace(anchor, helper + anchor, 1)
    p.write_text(src)

if 'TYPE_ASSIGN_REF' not in Path('lib/OpCode.php').read_text():
    Path('lib/OpCode.php').write_text(must_replace(Path('lib/OpCode.php').read_text(),
        '    public const SCRIPT_MAGIC_LINE = 3;\n\n    public int $type;',
        '    public const SCRIPT_MAGIC_LINE = 3;\n\n    const TYPE_ASSIGN_REF = 97;\n    const TYPE_DECLARE_GLOBAL = 98;\n\n    public int $type;', 'opcode'))

src = Path('lib/OpCodeNames.php').read_text()
if 'TYPE_ASSIGN_REF' not in src:
    Path('lib/OpCodeNames.php').write_text(must_replace(src,
        "        case 96:\n            return 'TYPE_SCRIPT_MAGIC';\n        default:",
        "        case 96:\n            return 'TYPE_SCRIPT_MAGIC';\n        case 97:\n            return 'TYPE_ASSIGN_REF';\n        case 98:\n            return 'TYPE_DECLARE_GLOBAL';\n        default:", 'names'))

src = Path('lib/VM/Context.php').read_text()
if 'ensureGlobal' not in src:
    src = must_replace(src, '    private array $superglobalVars = [];\n\n    public Runtime $runtime;',
        '    private array $superglobalVars = [];\n\n    /** @var array<string, Variable> */\n    private array $globalVars = [];\n\n    public Runtime $runtime;', 'vmctx1')
    src = must_replace(src,
        "    public function getSuperglobal(string $name): ?Variable\n    {\n        return $this->superglobalVars[$name] ?? null;\n    }\n\n    public function save(Frame $frame): RunStackEntry {",
        "    public function getSuperglobal(string $name): ?Variable\n    {\n        return $this->superglobalVars[$name] ?? null;\n    }\n\n    public function ensureGlobal(string $name): Variable\n    {\n        if (!isset($this->globalVars[$name])) {\n            $this->globalVars[$name] = new Variable(Variable::TYPE_NULL);\n        }\n        return $this->globalVars[$name];\n    }\n\n    public function save(Frame $frame): RunStackEntry {", 'vmctx2')
    Path('lib/VM/Context.php').write_text(src)

if 'TYPE_ASSIGN_REF' not in Path('lib/VM.php').read_text():
    Path('lib/VM.php').write_text(must_replace(Path('lib/VM.php').read_text(),
        """                    TypeCheck::coercePropertyWrite($arg2, $strict);
                    break;
                case OpCode::TYPE_ARRAY_DIM_FETCH:""",
        """                    TypeCheck::coercePropertyWrite($arg2, $strict);
                    break;
                case OpCode::TYPE_ASSIGN_REF:
                    $lhs = $frame->scope[$op->arg1];
                    $rhs = $frame->scope[$op->arg2]->resolveIndirect();
                    $lhs->indirect($rhs);
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL:
                    if (!isset($frame->block->constants[$op->arg2])) {
                        throw new \\LogicException('Global name must be a compile-time constant');
                    }
                    $globalName = $frame->block->constants[$op->arg2]->toString();
                    $frame->scope[$op->arg1]->indirect($this->context->ensureGlobal($globalName));
                    break;
                case OpCode::TYPE_ARRAY_DIM_FETCH:""", 'vm'))

if 'jitGlobalVariables' not in Path('lib/JIT/Context.php').read_text():
    Path('lib/JIT/Context.php').write_text(must_replace(Path('lib/JIT/Context.php').read_text(),
        '    public array $foreachObjNodeSlots = [];\n\n    public function __construct(Runtime $runtime, int $loadType) {',
        '    public array $foreachObjNodeSlots = [];\n\n    /** @var array<string, Variable> */\n    public array $jitGlobalVariables = [];\n\n    public function __construct(Runtime $runtime, int $loadType) {', 'jitctx'))

src = Path('lib/JIT.php').read_text()
old = """                case OpCode::TYPE_ASSIGN:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg3));
                    $destOp = $block->getOperand($op->arg1);
                    $forceCoalesce = $this->context->coalesceAssignTargets->contains($destOp);
                    $forceAssign = $forceCoalesce
                        || $this->assignOperandsUsedByLiteralInclude($block, $op);
                    $this->assignOperand($block->getOperand($op->arg2), $value, $forceAssign);
                    $this->assignOperand($destOp, $value, $forceAssign);
                    break;  """
if 'TYPE_ASSIGN_REF' not in src:
    src = src.replace(old, old + """
                case OpCode::TYPE_ASSIGN_REF:
                    $srcVar = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $this->context->setVariableOp($block->getOperand($op->arg1), $srcVar);
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL:
                    if (!isset($block->constants[$op->arg2])) {
                        throw new \\LogicException('Global name must be a compile-time constant');
                    }
                    $globalName = $block->constants[$op->arg2]->toString();
                    $this->context->setVariableOp(
                        $block->getOperand($op->arg1),
                        $this->ensureJitGlobal($globalName)
                    );
                    break;  """, 1)
if 'function ensureJitGlobal' not in src:
    src = src.rstrip()
    if src.endswith('}'):
        src = src[:-1]
    src += Path('script/inventory-blocker-jit-helper.php-snippet').read_text()
Path('lib/JIT.php').write_text(src)
print('patch applied')
PY
