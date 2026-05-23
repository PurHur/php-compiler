#!/usr/bin/env python3
import re
from pathlib import Path
ROOT = Path('/home/ai/php-compiler')

(ROOT / "lib/JIT/JitLongArg.php").write_text('''<?php
declare(strict_types=1);
namespace PHPCompiler\\JIT;
use PHPLLVM\\Value;
final class JitLongArg {
    public static function lower(Context $context, Variable $arg, string $contextLabel = "argument"): Value {
        if (Variable::TYPE_NATIVE_LONG === $arg->type) return $context->helper->loadValue($arg);
        if (Variable::TYPE_NATIVE_BOOL === $arg->type) return $context->builder->zExt($context->helper->loadValue($arg), $context->getTypeFromString("int64"));
        if (Variable::TYPE_VALUE === $arg->type) return $context->builder->call($context->lookupFunction("__value__readLong"), JitValueBox::valuePtrFromVariable($context, $arg));
        if (Variable::TYPE_NULL === $arg->type) return $context->getTypeFromString("int64")->constInt(0, false);
        if (Variable::TYPE_OBJECT === $arg->type) return $context->builder->ptrToInt($context->helper->loadValue($arg), $context->getTypeFromString("int64"));
        throw new \\LogicException("{$contextLabel} must be an integer in this compiler build");
    }
}
''')

(ROOT / "lib/JIT/JitBoolArg.php").write_text('''<?php
declare(strict_types=1);
namespace PHPCompiler\\JIT;
use PHPLLVM\\Value;
final class JitBoolArg {
    public static function lower(Context $context, Variable $arg, string $contextLabel = "argument"): Value {
        if (Variable::TYPE_NATIVE_BOOL === $arg->type) return $context->helper->loadValue($arg);
        if (Variable::TYPE_NATIVE_LONG === $arg->type) return $context->builder->truncOrBitCast($context->helper->loadValue($arg), $context->getTypeFromString("int1"));
        if (Variable::TYPE_VALUE === $arg->type) return $context->builder->truncOrBitCast($context->builder->call($context->lookupFunction("__value__readLong"), JitValueBox::valuePtrFromVariable($context, $arg)), $context->getTypeFromString("int1"));
        if (Variable::TYPE_NULL === $arg->type) return $context->constantFromBool(false);
        throw new \\LogicException("{$contextLabel} must be a boolean in this compiler build");
    }
}
''')

(ROOT / "lib/Func/Internal.php").write_text('''<?php
declare(strict_types=1);
namespace PHPCompiler\\Func;
use PHPCompiler\\Frame; use PHPCompiler\\Func; use PHPCompiler\\Handler; use PHPCompiler\\JIT\\Call;
use PHPCompiler\\JIT\\Context as JITContext; use PHPCompiler\\JIT\\JitBoolArg; use PHPCompiler\\JIT\\JitLongArg;
use PHPCompiler\\JIT\\JitStringArg; use PHPCompiler\\JIT\\Variable as JITVariable; use PHPCompiler\\VM\\Context; use PHPLLVM\\Value;
abstract class Internal extends Func implements Handler, Call {
    public function __construct(string $name = null) { if (null === $name) { $parts = explode("\\\\", get_class($this)); $name = end($parts); } parent::__construct($name); }
    public function getFrame(Context $context, ?Frame $frame = null): Frame { return new Frame($this, null, null); }
    protected function jitString(JITContext $context, JITVariable $arg, string $contextLabel = "argument"): Value { return JitStringArg::lower($context, $arg, $contextLabel); }
    protected function jitLong(JITContext $context, JITVariable $arg, string $contextLabel = "argument"): Value { return JitLongArg::lower($context, $arg, $contextLabel); }
    protected function jitBool(JITContext $context, JITVariable $arg, string $contextLabel = "argument"): Value { return JitBoolArg::lower($context, $arg, $contextLabel); }
    protected function stringDataPtr(JITContext $context, Value $strPtr): Value {
        $structName = $strPtr->typeOf()->getElementType()->getName(); $off = $context->structFieldMap[$structName]["value"];
        return $context->builder->structGep($strPtr, $off);
    }
}
''')

(ROOT / "test/bootstrap-aot/stdlib_numeric.php").write_text('''<?php
declare(strict_types=1);
$n = intval("42"); $b = boolval($n); $f = floatval($b); $items = ["a", "b", "c"];
echo (string) ($n + intval($f));
echo is_int($n) ? "1" : "0"; echo is_string("x") ? "1" : "0"; echo is_array($items) ? "1" : "0";
echo is_null(null) ? "1" : "0"; echo is_bool($b) ? "1" : "0"; echo is_float($f) ? "1" : "0";
echo (string) count($items); echo (string) strlen("abc"); echo is_numeric("3.14") ? "1" : "0"; echo sprintf("%d", $n);
''')

(ROOT / "test/bootstrap-aot/stdlib_hash.php").write_text('''<?php
declare(strict_types=1);
$data = "bootstrap"; $key = "secret";
echo hash("md5", $data); echo hash_hmac("sha256", $data, $key);
''')

pat = re.compile(r"\n\s*private function stringDataPtr\(Context \$context, Value \$strPtr\): Value\n\s*\{.*?\n\s*\}\n", re.S)
for p in ROOT.glob("ext/standard/*.php"):
    t = p.read_text(); p.write_text(pat.sub("\n", t))

NO = """            case 'int64':
                switch ($arg->type) {
                    case Variable::TYPE_NATIVE_LONG: return $value;
                    case Variable::TYPE_NATIVE_BOOL: return $context->builder->zExt($value, $context->getTypeFromString('int64'));
                    case Variable::TYPE_VALUE: return $context->builder->call($context->lookupFunction('__value__readLong'), \\PHPCompiler\\JIT\\JitValueBox::valuePtrFromVariable($context, $arg));
                    case Variable::TYPE_OBJECT: return $context->builder->ptrToInt($value, $context->getTypeFromString('int64'));
                    case Variable::TYPE_NULL: return $context->getTypeFromString('int64')->constInt(0, true);
                } break;
            case 'int1': case 'bool':
                switch ($arg->type) {
                    case Variable::TYPE_NATIVE_BOOL: return $value;
                    case Variable::TYPE_NATIVE_LONG: return $context->builder->truncOrBitCast($value, $context->getTypeFromString('int1'));
                    case Variable::TYPE_VALUE: return $context->builder->truncOrBitCast($context->builder->call($context->lookupFunction('__value__readLong'), \\PHPCompiler\\JIT\\JitValueBox::valuePtrFromVariable($context, $arg)), $context->getTypeFromString('int1'));
                } break;"""
NN = """            case 'int64': return JitLongArg::lower($context, $arg, "argument {$argNum} for {$this->name}()");
            case 'int1': case 'bool': return JitBoolArg::lower($context, $arg, "argument {$argNum} for {$this->name}()");"""
for rel in ("lib/JIT/Call/Native.pre", "lib/JIT/Call/Native.php"):
    p = ROOT / rel; t = p.read_text().replace(NO, NN, 1)
    if "JitLongArg" not in t: t = t.replace("use PHPCompiler\\JIT\\JitStringArg;", "use PHPCompiler\\JIT\\JitBoolArg;\nuse PHPCompiler\\JIT\\JitLongArg;\nuse PHPCompiler\\JIT\\JitStringArg;")
    p.write_text(t)

for rel in ("ext/types/strlen.php", "ext/types/strlen.pre"):
    p = ROOT / rel; t = p.read_text()
    if "JitStringArg" not in t: t = t.replace("use PHPCompiler\\JIT\\Variable;", "use PHPCompiler\\JIT\\JitStringArg;\nuse PHPCompiler\\JIT\\Variable;")
    old = "        $argValue = $context->helper->loadValue($args[0]);\n        switch ($args[0]->type) {\n            case Variable::TYPE_STRING:"
    if old in t:
        if rel.endswith(".pre"):
            t = t.replace(old, "        $argValue = JitStringArg::lower($context, $args[0], 'strlen() string');\n                compile {", 1)
            t = re.sub(r"\n\s*\}\n\s*throw new[^\n]+\n", "\n", t)
        else:
            t = t.replace(old, "        $argValue = JitStringArg::lower($context, $args[0], 'strlen() string');\n        $offset = $this->context->structFieldMap[$argValue->typeOf()->getElementType()->getName()]['length'];\n            $result = $this->context->builder->load($this->context->builder->structGep($argValue, $offset));\n        return $result;\n__X__", 1)
            t = re.sub(r"\n\s*return \$result;\n__X__[\s\S]*?throw[^\n]+\n", "\n", t)
    p.write_text(t)

for fname, idx, label in (("hash_.php", 2, "hash"), ("hash_hmac.php", 3, "hash_hmac")):
    p = ROOT / f"ext/standard/{fname}"; t = p.read_text()
    if "JitStringArg" not in t: t = t.replace("use PHPCompiler\\JIT\\Variable as JITVariable;", "use PHPCompiler\\JIT\\JitBoolArg;\nuse PHPCompiler\\JIT\\JitStringArg;\nuse PHPCompiler\\JIT\\Variable as JITVariable;")
    t = re.sub(r"\n\s*private static function jitStringArg\(Context \$context, JITVariable \$arg\): Value\n\{.*?\n\s*\}\n", "\n", t, flags=re.S)
    body = "return JitHash::hash($context, JitStringArg::lower($context, $args[0], 'hash() algorithm'), JitStringArg::lower($context, $args[1], 'hash() data'), $raw);" if label=="hash" else "return JitHash::hashHmac($context, JitStringArg::lower($context, $args[0], 'hash_hmac() algorithm'), JitStringArg::lower($context, $args[1], 'hash_hmac() data'), JitStringArg::lower($context, $args[2], 'hash_hmac() key'), $raw);"
    t = re.sub(r"\$raw = \$context->getTypeFromString\('int1'\)->constInt\(0, false\);.*?return JitHash::", f"$raw = $context->getTypeFromString('int1')->constInt(0, false);\n        if (isset($args[{idx}])) {{ $raw = JitBoolArg::lower($context, $args[{idx}], '{label}() raw_output'); }}\n        {body}", t, count=1, flags=re.S)
    p.write_text(t)

p = ROOT / "ext/standard/is_numeric.php"; t = p.read_text()
if "valueIsNumeric" not in t:
    t = t.replace("use PHPCompiler\\JIT\\Variable as JITVariable;", "use PHPCompiler\\JIT\\JitValueBox;\nuse PHPCompiler\\JIT\\Variable as JITVariable;")
    t = t.replace("case JITVariable::TYPE_STRING:\n                return $this->stringIsNumeric($context, $context->helper->loadValue($args[0]));\n            default:", "case JITVariable::TYPE_STRING:\n                return $this->stringIsNumeric($context, $context->helper->loadValue($args[0]));\n            case JITVariable::TYPE_VALUE:\n                return $this->valueIsNumeric($context, $args[0]);\n            default:")
    t = t.replace("}\n\n}", "}\n\n    private function valueIsNumeric(Context $context, JITVariable $arg): Value {\n        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);\n        $map = $context->structFieldMap['__value__'];\n        $typeByte = $context->builder->load($context->builder->structGep($valuePtr, $map['type']));\n        $i8 = $context->getTypeFromString('int8');\n        $falseVal = $context->constantFromBool(false); $trueVal = $context->constantFromBool(true);\n        $isLong = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false));\n        $isDouble = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false));\n        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_STRING, false));\n        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);\n        $stringNumeric = $this->stringIsNumeric($context, $stringVal);\n        return $context->builder->select($isLong, $trueVal, $context->builder->select($isDouble, $trueVal, $context->builder->select($isString, $stringNumeric, $falseVal)));\n    }\n\n}")
    p.write_text(t)

p = ROOT / "ext/types/is_type.php"; t = p.read_text()
if "JitValueBox::valuePtrFromVariable" not in t:
    t = t.replace("use PHPCompiler\\JIT\\Variable as JITVariable;", "use PHPCompiler\\JIT\\JitValueBox;\nuse PHPCompiler\\JIT\\Variable as JITVariable;")
    t = t.replace("$loaded = $context->helper->loadValue($args[0]);\n                $loaded = $context->builder->pointerCast(\n                    $loaded,\n                    $context->getTypeFromString('__value__*')\n                );", "$loaded = JitValueBox::valuePtrFromVariable($context, $args[0]);")
    p.write_text(t)

(ROOT / "ext/standard/array_count.php").write_text((ROOT / "ext/standard/array_count.php").read_text().replace("JitValueBox::pointer($context, $args[0]->value)", "JitValueBox::valuePtrFromVariable($context, $args[0])"))

p = ROOT / "ext/standard/JitSprintf.php"; t = p.read_text()
if "JitStringArg::lower" not in t:
    t = t.replace("use PHPCompiler\\JIT\\JitValueBox;", "use PHPCompiler\\JIT\\JitStringArg;\nuse PHPCompiler\\JIT\\JitValueBox;")
    t = re.sub(r"if \(JITVariable::TYPE_STRING !== \$args\[0\]->type\) \{.*?\}\n        \$fmt = \$context->helper->loadValue\(\$args\[0\]\);", "$fmt = JitStringArg::lower($context, $args[0], 'sprintf() format');", t, count=1, flags=re.S)
    p.write_text(t)

HO = "                $leftPtr = Variable::KIND_VARIABLE === $left->kind ? $left->value : $this->loadValue($left);\n                $leftLong = $this->context->builder->call(\n                    $this->context->lookupFunction('__value__readLong'),\n                    $leftPtr\n                );"
HN = "                $leftLong = JitLongArg::lower($this->context, $left, 'binary op left operand');"
EB = "\n            if (OpCode::TYPE_EQUAL === $opcode->type || OpCode::TYPE_NOT_EQUAL === $opcode->type) {\n                if (Variable::TYPE_NATIVE_LONG === $rightType) {\n                    $leftLong = JitLongArg::lower($this->context, $left, 'binary op left operand');\n                    $__right = $this->context->builder->intCast($rightValue, $leftLong->typeOf());\n                    $cmp = OpCode::TYPE_EQUAL === $opcode->type ? Builder::INT_EQ : Builder::INT_NE;\n                    $result = $this->context->builder->icmp($cmp, $leftLong, $__right);\n                    goto return_bool;\n                }\n            }"
IO = "\n    private static function isOrderedCompareOpcode(int $opcodeType): bool {\n        return OpCode::TYPE_GREATER === $opcodeType || OpCode::TYPE_GREATER_OR_EQUAL === $opcodeType || OpCode::TYPE_SMALLER === $opcodeType || OpCode::TYPE_SMALLER_OR_EQUAL === $opcodeType;\n    }\n"
for rel in ("lib/JIT/Helper.php", "lib/JIT/Helper.pre"):
    p = ROOT / rel; t = p.read_text()
    if HO in t: t = t.replace(HO, HN, 1)
    mid = t.split("Variable::TYPE_VALUE === $leftType && Variable::TYPE_VALUE !== $rightType")
    if len(mid)>1 and "TYPE_EQUAL === $opcode->type || OpCode::TYPE_NOT_EQUAL" not in mid[1].split("Variable::TYPE_VALUE === $rightType")[0]:
        t = t.replace("            if (OpCode::TYPE_IDENTICAL === $opcode->type) {", EB + "\n            if (OpCode::TYPE_IDENTICAL === $opcode->type) {", 1)
    if "isOrderedCompareOpcode" not in t: t = t.replace("\n}\n", IO + "\n}\n")
    p.write_text(t)

p = ROOT / "lib/JIT/Builtin/Type/Object_.php"; t = p.read_text()
if "phptypes\\\\type" not in t:
    t = t.replace("                'kind_value' => \\PHPCompiler\\JIT\\Variable::KIND_VALUE,\n            ]);\n        }\n    }\n\n    private function registerExternalClass", "                'kind_value' => \\PHPCompiler\\JIT\\Variable::KIND_VALUE,\n            ]);\n        }\n        if ('phptypes\\\\type' === $lcname || 'type' === $lcname) {\n            $seed(['type_null'=>\\PHPTypes\\Type::TYPE_NULL,'type_boolean'=>\\PHPTypes\\Type::TYPE_BOOLEAN,'type_long'=>\\PHPTypes\\Type::TYPE_LONG,'type_double'=>\\PHPTypes\\Type::TYPE_DOUBLE,'type_string'=>\\PHPTypes\\Type::TYPE_STRING,'type_object'=>\\PHPTypes\\Type::TYPE_OBJECT,'type_array'=>\\PHPTypes\\Type::TYPE_ARRAY,'type_callable'=>\\PHPTypes\\Type::TYPE_CALLABLE,'type_union'=>\\PHPTypes\\Type::TYPE_UNION,'type_intersection'=>\\PHPTypes\\Type::TYPE_INTERSECTION]);\n        }\n    }\n\n    private function registerExternalClass")
    t = t.replace("                ];\n            }\n        }\n    }\n\n    /**\n     * JIT storage type for properties on vendor CFG / compiler objects (e.g. PHPCfg\\Block::$children).\n     */", "                ];\n            }\n        }\n        if ('phptypes\\\\type' === $lcname || 'type' === $lcname) {\n            foreach (['type_null'=>\\PHPTypes\\Type::TYPE_NULL,'type_boolean'=>\\PHPTypes\\Type::TYPE_BOOLEAN,'type_long'=>\\PHPTypes\\Type::TYPE_LONG,'type_double'=>\\PHPTypes\\Type::TYPE_DOUBLE,'type_string'=>\\PHPTypes\\Type::TYPE_STRING,'type_object'=>\\PHPTypes\\Type::TYPE_OBJECT,'type_array'=>\\PHPTypes\\Type::TYPE_ARRAY,'type_callable'=>\\PHPTypes\\Type::TYPE_CALLABLE,'type_union'=>\\PHPTypes\\Type::TYPE_UNION,'type_intersection'=>\\PHPTypes\\Type::TYPE_INTERSECTION] as $name=>$value) {\n                $this->classConstants[$id][$name] = ['type'=>Variable::TYPE_NATIVE_LONG,'value'=>$value];\n            }\n        }\n    }\n\n    /**\n     * JIT storage type for properties on vendor CFG / compiler objects (e.g. PHPCfg\\Block::$children).\n     */")
    p.write_text(t)

(ROOT / "script/apply-stdlib-numeric-jit-batch.py").write_text(Path("/tmp/apply-numeric.py").read_text())
print("OK")
