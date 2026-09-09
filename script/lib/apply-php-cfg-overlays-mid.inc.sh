# Sourced from script/apply-patches.sh — mid php-cfg overlays (#36403 size ratchet).
# Requires ROOT, PATCH_DIR, and helpers: install_overlay_file, patch_already_applied.
# Also hosts interleaved php-types halt-offset + php-parser final-property overlays.
# shellcheck shell=bash

apply_php_cfg_class_const_flags_overlay() {
  local const_file="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Const_.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if patch_already_applied "$PATCH_DIR/php-cfg-class-const-flags.patch"; then
    echo "Skip php-cfg-class-const-flags.patch (already applied)"
    return 0
  fi
  python3 - "$const_file" "$parser" <<'PY'
import sys
from pathlib import Path

const_path = Path(sys.argv[1])
parser_path = Path(sys.argv[2])
modified = False
const_text = const_path.read_text()
if 'public int $flags = 0' not in const_text:
    for old in (
        "    public ?Type $declaredType = null;\n\n    public function __construct",
        "    public $valueBlock;\n\n    public function __construct",
    ):
        if old in const_text:
            const_text = const_text.replace(
                old,
                old.replace(
                    "\n\n    public function __construct",
                    "\n\n    /** PhpParser Stmt\\ClassConst flags (MODIFIER_FINAL, visibility, etc.). */\n"
                    "    public int $flags = 0;\n\n    public function __construct",
                    1,
                ),
                1,
            )
            const_path.write_text(const_text)
            modified = True
            break
    else:
        sys.stderr.write("php-cfg-class-const-flags: Const_.php anchor not found\n")
        raise SystemExit(1)

text = parser_path.read_text()
if '$constOp->flags = $node->flags' not in text:
    old = "$constOp->declaredType = null !== $node->type ? $this->parseTypeNode($node->type) : null;\n            $this->block->children[] = $constOp;"
    new = old.replace(
        "\n            $this->block->children[] = $constOp;",
        "\n            $constOp->flags = $node->flags;\n            $this->block->children[] = $constOp;",
        1,
    )
    if old not in text:
        sys.stderr.write("php-cfg-class-const-flags: Parser parseStmt_ClassConst anchor not found\n")
        raise SystemExit(1)
    parser_path.write_text(text.replace(old, new, 1))
    modified = True
print("Applied php-cfg-class-const-flags.patch (overlay)" if modified else "Skip php-cfg-class-const-flags.patch (already applied)")
PY
}

apply_php_cfg_in_operator_overlay() {
  install_overlay_file \
    $ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/In_.php \
    $PATCH_DIR/overlays/php-cfg/Op/Expr/In_.php \
    "php-cfg-in-operator overlay (In_.php)"
}

apply_php_cfg_exit_two_arg_overlay() {
  install_overlay_file \
    $ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Exit_.php \
    $PATCH_DIR/overlays/php-cfg/Op/Expr/Exit_.php \
    "php-cfg-exit-two-arg overlay (Exit_.php)"
}

apply_php_cfg_void_cast_overlay() {
  install_overlay_file \
    $ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Cast/Void_.php \
    $PATCH_DIR/overlays/php-cfg/Op/Expr/Cast/Void_.php \
    "php-cfg-void-cast overlay (Void_.php)"
}

apply_php_cfg_typed_class_const_overlay() {
  local const_op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Const_.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/Op/Terminal/Const_.php"
  if patch_already_applied "$PATCH_DIR/php-cfg-typed-class-const.patch"; then
    echo "Skip php-cfg-typed-class-const.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay" ]]; then
    echo "Skip php-cfg-typed-class-const overlay (missing $overlay)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$const_op")"
  cp "$overlay" "$const_op"
  python3 - "$parser" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
if 'declaredType = null !== $node->type' in text:
    sys.exit(0)
old = """            $this->block->children[] = new Op\\Terminal\\Const_(
                $this->parseExprNode($const->name),
                $value, $valueBlock,
                $this->mapAttributes($node)
            );"""
new = """            $constOp = new Op\\Terminal\\Const_(
                $this->parseExprNode($const->name),
                $value, $valueBlock,
                $this->mapAttributes($node)
            );
            $constOp->declaredType = null !== $node->type ? $this->parseTypeNode($node->type) : null;
            $constOp->flags = $node->flags;
            $this->block->children[] = $constOp;"""
if old not in text:
    raise SystemExit('php-cfg typed class const: parseStmt_ClassConst anchor missing')
parser_path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-cfg-typed-class-const overlay (#6012)"
}

apply_php_cfg_global_typed_const_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/global-typed-const-parser-methods.php"
  if [[ ! -f "$parser" || ! -f "$overlay" ]]; then
    return 0
  fi
  if grep -q 'function applyGlobalTypedConstMarkerAttributes' "$parser" 2>/dev/null; then
    python3 - "$parser" <<'PY'
import re
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
pattern = re.compile(
    r"/\*\*\n(?:     )?\* Parse a type expression embedded in a global typed-const marker via php-parser \(#7081\)\.\n(?:     )?\*/\n    private function parseGlobalTypedConstTypeFromMarker\(string \$typeExpr\): Op\\Type\n    \{.*?\n        return \$this->parseTypeNode\(\$class->stmts\[0\]->type\);\n    \}\n",
    re.S,
)
matches = list(pattern.finditer(text))
if len(matches) <= 1:
    raise SystemExit(0)
text = text[: matches[1].start()] + text[matches[1].end() :]
parser_path.write_text(text)
PY
    return 0
  fi
  python3 - "$parser" "$overlay" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
if 'function applyGlobalTypedConstMarkerAttributes' in text:
    raise SystemExit(0)

anchor = """    protected function parseExpr_Yield(Expr\\Yield_ $expr)
    {"""
if anchor not in text:
    sys.stderr.write("php-cfg-global-typed-const: parseExpr_Yield anchor not found in Parser.php\n")
    raise SystemExit(1)

insert = method_path.read_text().rstrip("\n") + "\n\n"
if 'function extractGlobalTypedConstDeclaredTypeFromAttributes' in text:
    old_methods = """    /**
     * Recover phpc-global-typed-const:* marker from comment attributes (#7081).
     */
    private function extractGlobalTypedConstDeclaredTypeFromAttributes(array $attributes): ?Op\\Type
    {
        $chunks = [];
        if (isset($attributes['comments']) && is_array($attributes['comments'])) {
            foreach ($attributes['comments'] as $comment) {
                if (is_object($comment) && method_exists($comment, 'getText')) {
                    $chunks[] = $comment->getText();
                } elseif (is_string($comment)) {
                    $chunks[] = $comment;
                }
            }
        }
        if (isset($attributes['docComment']) && is_object($attributes['docComment'])
            && method_exists($attributes['docComment'], 'getText')) {
            $chunks[] = $attributes['docComment']->getText();
        }
        foreach ($chunks as $chunk) {
            if (!preg_match(\\PHPCompiler\\Ast\\GlobalTypedConstRewriter::MARKER_PATTERN, $chunk, $m)) {
                continue;
            }
            $typeExpr = trim($m[1]);
            if ('' === $typeExpr) {
                continue;
            }

            return $this->parseGlobalTypedConstTypeFromMarker($typeExpr);
        }

        return null;
    }

    """
    if old_methods not in text:
        sys.stderr.write("php-cfg-global-typed-const: upgrade anchor missing (#9909)\n")
        raise SystemExit(1)
    text = text.replace(old_methods, insert, 1)
    text = text.replace(
        "$constOp->declaredType = $this->extractGlobalTypedConstDeclaredTypeFromAttributes($node->getAttributes());",
        "$this->applyGlobalTypedConstMarkerAttributes($constOp, $node->getAttributes());",
        1,
    )
    trailing_method = """    /**
     * Parse a type expression embedded in a global typed-const marker via php-parser (#7081).
     */
    private function parseGlobalTypedConstTypeFromMarker(string $typeExpr): Op\\Type
    {
        static $parser = null;
        if (null === $parser) {
            $parser = (new \\PhpParser\\ParserFactory())->create(\\PhpParser\\ParserFactory::PREFER_PHP7);
        }
        $probe = '<?php class __PhpcGlobalTypedConstProbe { public '.$typeExpr.' $p; }';
        try {
            $ast = $parser->parse($probe);
        } catch (\\PhpParser\\Error $e) {
            throw new \\RuntimeException('Invalid typed global constant type: '.$typeExpr, 0, $e);
        }
        if (!is_array($ast) || !isset($ast[0]) || !($ast[0] instanceof \\PhpParser\\Node\\Stmt\\Class_)) {
            throw new \\RuntimeException('Invalid typed global constant type probe: '.$typeExpr);
        }
        $class = $ast[0];
        if (!isset($class->stmts[0]) || !($class->stmts[0] instanceof \\PhpParser\\Node\\Stmt\\Property)) {
            throw new \\RuntimeException('Invalid typed global constant type probe property: '.$typeExpr);
        }

        return $this->parseTypeNode($class->stmts[0]->type);
    }

"""
    if text.count('function parseGlobalTypedConstTypeFromMarker') > 1 and trailing_method in text:
        text = text.replace(trailing_method, '', 1)
else:
    text = text.replace(anchor, insert + anchor, 1)
    old = """            $this->block->children[] = new Op\\Terminal\\Const_(
                $this->parseExprNode($const->namespacedName),
                $value, $valueBlock,
                $this->mapAttributes($node)
            );"""
    new = """            $constOp = new Op\\Terminal\\Const_(
                $this->parseExprNode($const->namespacedName),
                $value, $valueBlock,
                $this->mapAttributes($node)
            );
            $this->applyGlobalTypedConstMarkerAttributes($constOp, $node->getAttributes());
            $this->block->children[] = $constOp;"""
    if old not in text:
        sys.stderr.write("php-cfg-global-typed-const: parseStmt_Const anchor missing\n")
        raise SystemExit(1)
    text = text.replace(old, new, 1)

parser_path.write_text(text)
PY
  echo "Applied php-cfg-global-typed-const overlay (#7081, #9909)"
}

apply_php_cfg_global_deprecated_const_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/global-deprecated-const-parser-methods.php"
  if [[ ! -f "$parser" || ! -f "$overlay" ]]; then
    return 0
  fi
  # Healthy: ATTRS recovery present, helper present, and no leftover extract*
  # duplicate from the #23882 refresh that only replaced the first method
  # (Fatal: Cannot redeclare extractGlobalDeprecatedConstMarkerPayloadFromAttributes).
  local extract_count
  extract_count="$(grep -c 'function extractGlobalDeprecatedConstMarkerPayloadFromAttributes' "$parser" 2>/dev/null || true)"
  if grep -q 'function applyGlobalDeprecatedConstMarkerAttributes' "$parser" 2>/dev/null \
    && grep -q 'ATTRS_MARKER_PATTERN' "$parser" 2>/dev/null \
    && grep -q 'function globalConstMarkerCommentChunks' "$parser" 2>/dev/null \
    && [[ "${extract_count}" -eq 0 ]]; then
    return 0
  fi
  python3 - "$parser" "$overlay" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
insert = method_path.read_text().rstrip("\n") + "\n\n"

yield_anchor = """    protected function parseExpr_Yield(Expr\\Yield_ $expr)
    {"""
if yield_anchor not in text:
    sys.stderr.write("php-cfg-global-deprecated-const: parseExpr_Yield anchor not found in Parser.php\n")
    raise SystemExit(1)

# Replace the whole marker-helper block through parseExpr_Yield so a prior
# refresh cannot leave a second extract* method behind (#23882 leftover).
block_starts = []
for needle in (
    "    private function applyGlobalDeprecatedConstMarkerAttributes",
    "    private function globalConstMarkerCommentChunks",
    "    private function extractGlobalDeprecatedConstMarkerPayloadFromAttributes",
):
    pos = text.find(needle)
    if pos >= 0:
        block_starts.append(pos)

if block_starts:
    start = min(block_starts)
    end = text.find(yield_anchor)
    if end < start:
        sys.stderr.write("php-cfg-global-deprecated-const: yield anchor before overlay block\n")
        raise SystemExit(1)
    text = text[:start] + insert + text[end:]
    # Ensure parseStmt_Const wires apply* (idempotent).
    old_anchor = """            $this->applyGlobalTypedConstMarkerAttributes($constOp, $node->getAttributes());
            $this->block->children[] = $constOp;"""
    new_anchor = """            $this->applyGlobalTypedConstMarkerAttributes($constOp, $node->getAttributes());
            $this->applyGlobalDeprecatedConstMarkerAttributes($constOp, $node->getAttributes());
            $this->block->children[] = $constOp;"""
    if old_anchor in text:
        text = text.replace(old_anchor, new_anchor, 1)
    parser_path.write_text(text)
    print("Healed php-cfg-global-deprecated-const overlay (#16819, #23882)")
    raise SystemExit(0)

anchor = """            $this->applyGlobalTypedConstMarkerAttributes($constOp, $node->getAttributes());
            $this->block->children[] = $constOp;"""
if anchor not in text:
    sys.stderr.write("php-cfg-global-deprecated-const: parseStmt_Const anchor missing\n")
    raise SystemExit(1)

text = text.replace(yield_anchor, insert + yield_anchor, 1)
text = text.replace(
    anchor,
    """            $this->applyGlobalTypedConstMarkerAttributes($constOp, $node->getAttributes());
            $this->applyGlobalDeprecatedConstMarkerAttributes($constOp, $node->getAttributes());
            $this->block->children[] = $constOp;""",
    1,
)
parser_path.write_text(text)
print("Applied php-cfg-global-deprecated-const overlay (#16819, #23882)")
PY
}

apply_php_cfg_typed_function_static_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/typed-function-static-parser-methods.php"
  if [[ ! -f "$parser" || ! -f "$overlay" ]]; then
    return 0
  fi
  if grep -q 'function applyTypedFunctionStaticMarkerAttributes' "$parser" 2>/dev/null; then
    return 0
  fi
  python3 - "$parser" "$overlay" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
if 'function applyTypedFunctionStaticMarkerAttributes' in text:
    raise SystemExit(0)

anchor = """    protected function parseStmt_Static(Stmt\\Static_ $node)
    {"""
if anchor not in text:
    sys.stderr.write("php-cfg-typed-function-static: parseStmt_Static anchor not found in Parser.php\n")
    raise SystemExit(1)

insert = method_path.read_text().rstrip("\n") + "\n\n"
text = text.replace(anchor, insert + anchor, 1)
old = """            $this->block->children[] = new Op\\Terminal\\StaticVar(
                $this->writeVariable(new Operand\\BoundVariable($this->parseExprNode($var->var->name), true, Operand\\BoundVariable::SCOPE_FUNCTION)),
                $defaultBlock,
                $defaultVar,
                $this->mapAttributes($node)
            );"""
new = """            $staticOp = new Op\\Terminal\\StaticVar(
                $this->writeVariable(new Operand\\BoundVariable($this->parseExprNode($var->var->name), true, Operand\\BoundVariable::SCOPE_FUNCTION)),
                $defaultBlock,
                $defaultVar,
                $this->mapAttributes($node)
            );
            $this->applyTypedFunctionStaticMarkerAttributes(
                $staticOp,
                array_merge($node->getAttributes(), $var->getAttributes())
            );
            $this->block->children[] = $staticOp;"""
if old not in text:
    sys.stderr.write("php-cfg-typed-function-static: parseStmt_Static body anchor missing\n")
    raise SystemExit(1)
text = text.replace(old, new, 1)
parser_path.write_text(text)
PY
  echo "Applied php-cfg-typed-function-static overlay (#9998)"
}

apply_php_cfg_list_spread_overlay() {
  local assign="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Assign.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if grep -q 'listSpreadExcludedKeys = \$excludedKeys' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-list-spread.patch (already applied)"
    return 0
  fi
  python3 - "$assign" "$parser" <<'PY'
import sys
from pathlib import Path

assign_path = Path(sys.argv[1])
parser_path = Path(sys.argv[2])
assign = assign_path.read_text()
if 'listSpreadRhs' not in assign:
    needle = "    public $expr;\n\n    protected $writeVariables"
    insert = (
        "    public $expr;\n\n"
        "    /** `[$a, ...$rest] = $rhs` tail: full list RHS (#4835). */\n"
        "    public $listSpreadRhs = null;\n\n"
        "    /** Zero-based index of first element merged into the spread target (#4835). */\n"
        "    public $listSpreadFromIndex = null;\n\n"
        "    /** String literal keys consumed before spread (`['k' => $v, ...$tail]`, #4889). */\n"
        "    public $listSpreadExcludedKeys = [];\n\n"
        "    protected $writeVariables"
    )
    if needle not in assign:
        sys.stderr.write("php-cfg-list-spread: Assign.php anchor not found\n")
        raise SystemExit(1)
    assign_path.write_text(assign.replace(needle, insert, 1))

parser = parser_path.read_text()

old = """        $attributes = $this->mapAttributes($expr);
        foreach ($expr->items as $i => $item) {
            if (null === $item) {
                continue;
            }

            if ($item->key === null) {
                $key = new Operand\\Literal($i);
            } else {
                $key = $this->readVariable($this->parseExprNode($item->key));
            }

            $var = $item->value;
            $fetch = new Op\\Expr\\ArrayDimFetch($rhs, $key, $attributes);"""

new = """        $attributes = $this->mapAttributes($expr);
        $logicalIndex = 0;
        $excludedKeys = [];
        foreach ($expr->items as $i => $item) {
            if (null === $item) {
                continue;
            }

            if ($item->key === null) {
                $key = new Operand\\Literal($logicalIndex);
            } else {
                $key = $this->readVariable($this->parseExprNode($item->key));
            }

            $var = $item->value;
            if ($item->unpack) {
                $target = $this->writeVariable($this->parseExprNode($var));
                $assign = new Op\\Expr\\Assign($target, $rhs, $attributes);
                $assign->listSpreadRhs = $rhs;
                $assign->listSpreadFromIndex = $logicalIndex;
                $assign->listSpreadExcludedKeys = $excludedKeys;
                $this->block->children[] = $assign;

                continue;
            }

            if (null !== $item->key && $item->key instanceof Node\\Scalar\\String_) {
                $excludedKeys[] = $item->key->value;
            }

            if ($item->key === null) {
                ++$logicalIndex;
            }

            $fetch = new Op\\Expr\\ArrayDimFetch($rhs, $key, $attributes);"""

if old not in parser:
    sys.stderr.write("php-cfg-list-spread: Parser.php anchor not found\n")
    raise SystemExit(1)
parser_path.write_text(parser.replace(old, new, 1))
PY
  echo "Applied php-cfg-list-spread.patch (overlay)"
}

apply_php_cfg_empty_list_assignment_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if patch_already_applied "$PATCH_DIR/php-cfg-empty-list-assignment.patch"; then
    echo "Skip php-cfg-empty-list-assignment.patch (already applied)"
    return 0
  fi
  python3 - "$parser" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
if 'isEmptyListExpr' in text and "Cannot use empty list" in text:
    raise SystemExit(0)
# Prefer the post-listAssignment-attr shape (#22646); fall back to the older body.
candidates = [
    """    /**
     * @param Expr\\List_|Expr\\Array_ $expr
     */
    protected function parseListAssignment($expr, Operand $rhs)
    {
        $attributes = $this->mapAttributes($expr);
        // Marker for Compiler list-destruct detection — Runtime lexer omits startFilePos (#22646).
        $attributes['listAssignment'] = true;
        $logicalIndex = 0;""",
    """    /**
     * @param Expr\\List_|Expr\\Array_ $expr
     */
    protected function parseListAssignment($expr, Operand $rhs)
    {
        $attributes = $this->mapAttributes($expr);
        $logicalIndex = 0;""",
]
new = """    /**
     * @param Expr\\List_|Expr\\Array_ $expr
     */
    protected function isEmptyListExpr($expr): bool
    {
        foreach ($expr->items as $item) {
            if (null !== $item) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Expr\\List_|Expr\\Array_ $expr
     */
    protected function parseListAssignment($expr, Operand $rhs)
    {
        if ($this->isEmptyListExpr($expr)) {
            throw new \\CompileError('Cannot use empty list');
        }

        $attributes = $this->mapAttributes($expr);
        // Marker for Compiler list-destruct detection — Runtime lexer omits startFilePos (#22646).
        $attributes['listAssignment'] = true;
        $logicalIndex = 0;"""
for old in candidates:
    if old in text:
        # Keep listAssignment marker only when the matched anchor already had it.
        if "listAssignment" not in old:
            new = new.replace(
                "        $attributes = $this->mapAttributes($expr);\n"
                "        // Marker for Compiler list-destruct detection — Runtime lexer omits startFilePos (#22646).\n"
                "        $attributes['listAssignment'] = true;\n"
                "        $logicalIndex = 0;",
                "        $attributes = $this->mapAttributes($expr);\n"
                "        $logicalIndex = 0;",
            )
        parser_path.write_text(text.replace(old, new, 1))
        print("Applied php-cfg-empty-list-assignment.patch (overlay)")
        raise SystemExit(0)
sys.stderr.write("php-cfg-empty-list-assignment: Parser.php anchor not found\n")
raise SystemExit(1)
PY
}

apply_php_cfg_list_mix_keyed_unkeyed_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if patch_already_applied "$PATCH_DIR/php-cfg-list-mix-keyed-unkeyed.patch"; then
    echo "Skip php-cfg-list-mix-keyed-unkeyed.patch (already applied)"
    return 0
  fi
  python3 - "$parser" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
if 'rejectMixedKeyedUnkeyedListAssignment' in text:
    raise SystemExit(0)
old = """    /**
     * @param Expr\\List_|Expr\\Array_ $expr
     */
    protected function parseListAssignment($expr, Operand $rhs)
    {
        if ($this->isEmptyListExpr($expr)) {
            throw new \\CompileError('Cannot use empty list');
        }

        $attributes = $this->mapAttributes($expr);"""
new = """    /**
     * Zend zend_compile_list_assign — keyed vs unkeyed slots cannot mix (#14879).
     *
     * @param Expr\\List_|Expr\\Array_ $expr
     */
    protected function rejectMixedKeyedUnkeyedListAssignment($expr): void
    {
        $isKeyed = false;
        if (isset($expr->items[0]) && null !== $expr->items[0]) {
            $isKeyed = null !== $expr->items[0]->key;
        }
        foreach ($expr->items as $item) {
            if (null === $item || $item->unpack) {
                continue;
            }
            if ($isKeyed) {
                if (null === $item->key) {
                    throw new \\CompileError('Cannot mix keyed and unkeyed array entries in assignments');
                }
            } elseif (null !== $item->key) {
                throw new \\CompileError('Cannot mix keyed and unkeyed array entries in assignments');
            }
        }
    }

    /**
     * @param Expr\\List_|Expr\\Array_ $expr
     */
    protected function parseListAssignment($expr, Operand $rhs)
    {
        if ($this->isEmptyListExpr($expr)) {
            throw new \\CompileError('Cannot use empty list');
        }
        $this->rejectMixedKeyedUnkeyedListAssignment($expr);

        $attributes = $this->mapAttributes($expr);"""
if old not in text:
    sys.stderr.write("php-cfg-list-mix-keyed-unkeyed: Parser.php anchor not found\n")
    raise SystemExit(1)
parser_path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-cfg-list-mix-keyed-unkeyed.patch (overlay)"
}

apply_php_cfg_spread_overlay() {
  local operand="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Operand.php"
  local array_op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Array_.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if patch_already_applied "$PATCH_DIR/php-cfg-spread.patch"; then
    echo "Skip php-cfg-spread.patch (already applied)"
    return 0
  fi
  python3 - "$operand" "$array_op" "$parser" <<'PY'
import sys
from pathlib import Path

operand_path = Path(sys.argv[1])
array_path = Path(sys.argv[2])
parser_path = Path(sys.argv[3])

operand = operand_path.read_text()
if 'callArgUnpack' not in operand:
    needle = "    public $callArgName = null;\n\n    public function getType()"
    insert = (
        "    public $callArgName = null;\n\n"
        "    /** Spread/unpack at call site (...$expr) (issue #141). */\n"
        "    public $callArgUnpack = false;\n\n"
        "    public function getType()"
    )
    if needle not in operand:
        sys.stderr.write("php-cfg-spread: Operand.php anchor not found\n")
        raise SystemExit(1)
    operand_path.write_text(operand.replace(needle, insert, 1))

array_text = array_path.read_text()
if 'public $unpack' not in array_text:
    old_ctor = "    public function __construct(array $keys, array $values, array $byRef, array $attributes = [])\n    {\n        parent::__construct($attributes);\n        $this->keys = $this->addReadRefs(...$keys);\n        $this->values = $this->addReadRefs(...$values);\n        $this->byRef = $byRef;\n    }"
    new_ctor = (
        "    /** @var list<bool> parallel to values; true when element is ...$expr (issue #141). */\n"
        "    public $unpack;\n\n"
        "    public function __construct(array $keys, array $values, array $byRef, array $unpack = [], array $attributes = [])\n"
        "    {\n        parent::__construct($attributes);\n        $this->keys = $this->addReadRefs(...$keys);\n        $this->values = $this->addReadRefs(...$values);\n        $this->byRef = $byRef;\n        $this->unpack = $unpack;\n    }"
    )
    if old_ctor not in array_text:
        sys.stderr.write("php-cfg-spread: Array_.php constructor anchor not found\n")
        raise SystemExit(1)
    array_path.write_text(array_text.replace(old_ctor, new_ctor, 1))

parser = parser_path.read_text()
if 'function parseCallArgs' not in parser:
    old_after_parse_arg = """        return $site;
    }

    protected function parseExpr_Array(Expr\\Array_ $expr)"""
    new_after_parse_arg = """        return $site;
    }

    /**
     * @param list<Node\\Arg> $args
     *
     * @return Operand[]
     */
    protected function parseCallArgs(array $args): array
    {
        return array_map([$this, 'parseArg'], $args);
    }

    protected function parseExpr_Array(Expr\\Array_ $expr)"""
    if old_after_parse_arg in parser:
        parser = parser.replace(old_after_parse_arg, new_after_parse_arg, 1)
    else:
        old_arg = """        if (null !== $expr->name) {
            $op->callArgName = $expr->name->toString();
        }

        return $op;
    }

    protected function parseExpr_Array(Expr\\Array_ $expr)"""
        new_arg = """        if (null !== $expr->name) {
            $op->callArgName = $expr->name->toString();
        }
        $op->callArgUnpack = $expr->unpack;

        return $op;
    }

    /**
     * @param list<Node\\Arg> $args
     *
     * @return Operand[]
     */
    protected function parseCallArgs(array $args): array
    {
        return array_map([$this, 'parseArg'], $args);
    }

    protected function parseExpr_Array(Expr\\Array_ $expr)"""
        if old_arg not in parser:
            sys.stderr.write("php-cfg-spread: Parser.php parseArg anchor not found\\n")
            raise SystemExit(1)
        parser = parser.replace(old_arg, new_arg, 1)

if '$unpack[] = $item->unpack' not in parser:
    old_array = """        $keys = [];
        $values = [];
        $byRef = [];
        if ($expr->items) {
            foreach ($expr->items as $item) {
                if ($item->key) {
                    $keys[] = $this->readVariable($this->parseExprNode($item->key));
                } else {
                    $keys[] = new Operand\\NullOperand();
                }
                $values[] = $this->readVariable($this->parseExprNode($item->value));
                $byRef[] = $item->byRef;
            }
        }

        return new Op\\Expr\\Array_($keys, $values, $byRef, $this->mapAttributes($expr));"""
    new_array = """        $keys = [];
        $values = [];
        $byRef = [];
        $unpack = [];
        if ($expr->items) {
            foreach ($expr->items as $item) {
                if ($item->key) {
                    $keys[] = $this->readVariable($this->parseExprNode($item->key));
                } else {
                    $keys[] = new Operand\\NullOperand();
                }
                $values[] = $this->readVariable($this->parseExprNode($item->value));
                $byRef[] = $item->byRef;
                $unpack[] = $item->unpack;
            }
        }

        return new Op\\Expr\\Array_($keys, $values, $byRef, $unpack, $this->mapAttributes($expr));"""
    if old_array not in parser:
        sys.stderr.write("php-cfg-spread: Parser.php parseExpr_Array anchor not found\n")
        raise SystemExit(1)
    parser = parser.replace(old_array, new_array, 1)

parser = parser.replace(
    '$this->parseExprList($expr->args, self::MODE_READ)',
    '$this->parseCallArgs($expr->args)',
)
parser = parser.replace(
    '$args = $this->parseExprList($expr->args, self::MODE_READ);',
    '$args = $this->parseCallArgs($expr->args);',
)
if 'parseCallArgs($expr->args)' not in parser:
    sys.stderr.write("php-cfg-spread: Parser.php call-site anchor not found\n")
    raise SystemExit(1)
parser_path.write_text(parser)
PY
  echo "Applied php-cfg-spread.patch (overlay)"
}

apply_php_cfg_call_arg_site_clone_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if grep -q 'Per-call-site wrapper: unpack/named flags must not leak' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-call-arg-site-clone.patch (already applied)"
    return 0
  fi
  python3 - "$parser" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = """        $op = $this->readVariable($this->parseExprNode($expr->value));
        if (null !== $expr->name) {
            $op->callArgName = $expr->name->toString();
        }
        $op->callArgUnpack = $expr->unpack;

        return $op;"""
new = """        $op = $this->readVariable($this->parseExprNode($expr->value));
        // Per-call-site wrapper: unpack/named flags must not leak across calls on shared Var operands (#8560).
        $site = clone $op;
        $site->callArgName = null;
        $site->callArgUnpack = false;
        if (null !== $expr->name) {
            $site->callArgName = $expr->name->toString();
        }
        $site->callArgUnpack = $expr->unpack;

        return $site;"""
if old not in text:
    sys.stderr.write("php-cfg-call-arg-site-clone: Parser.php parseArg anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-cfg-call-arg-site-clone.patch (overlay)"
}

apply_php_cfg_simplifier_use_chain_overlay() {
  local simplifier="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Visitor/Simplifier.php"
  if [[ ! -f "$simplifier" ]]; then
    return 0
  fi
  local cfgwalk_count
  cfgwalk_count="$(grep -c 'private function replaceVariablesByCfgWalk' "$simplifier" 2>/dev/null || true)"
  if patch_already_applied "$PATCH_DIR/php-cfg-simplifier-use-chain.patch"; then
    if [[ "${cfgwalk_count:-0}" -le 1 ]]; then
      echo "Skip php-cfg-simplifier-use-chain.patch (already applied)"
      return 0
    fi
    echo "Repair php-cfg-simplifier-use-chain.patch (duplicate replaceVariablesByCfgWalk; #36250)"
  else
    # Pristine vendor: the committed .patch renames replaceVariables → replaceVariablesByCfgWalk.
    # The Python overlay below only flips replaceVariables and cannot create the helper on its own (#36377).
    if apply_patch_file_direct "$PATCH_DIR/php-cfg-simplifier-use-chain.patch"; then
      return 0
    fi
  fi
  python3 - "$simplifier" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()

new_replace_variables = """    private function replaceVariables(Operand $from, Operand $to, Block $block)
    {
        // Use-chain is the default (#23056): O(uses) instead of O(phis×blocks).
        // Opcode dumps match legacy on a 193-file corpus; CFG type pretty-print
        // can still differ. Opt out with PHPCFG_SIMPLIFIER_USECHAIN=0 or
        // PHPCFG_SIMPLIFIER_LEGACY=1 for bisect (#16077).
        $usechain = getenv('PHPCFG_SIMPLIFIER_USECHAIN');
        $legacy = ('0' === $usechain)
            || ('1' === getenv('PHPCFG_SIMPLIFIER_LEGACY'))
            || ('false' === strtolower((string) $usechain));
        if ($legacy) {
            $this->replaceVariablesByCfgWalk($from, $to, $block);

            return;
        }
        // Use-chain replacement: visit only the ops that actually reference
        // $from (Operand tracks usages/write-ops) instead of re-walking the
        // whole CFG per removed phi. The CFG walk was O(phis × blocks) and
        // took 121 s on a 32k-line file; this is O(uses) (#16077 / #23056).
        $ops = array_merge($from->usages, $from->ops);
        foreach ($ops as $op) {
            if ($op instanceof Op\\Phi) {
                if ($op->hasOperand($from)) {
                    // Since we're removing from the phi, it may become trivial.
                    // The stored block is only carried through to
                    // tryRemoveTrivialPhi, which no longer depends on it.
                    $this->trivialPhiCandidates[$op] = $block;
                    $op->removeOperand($from);
                    $op->addOperand($to);
                }

                continue;
            }
            $this->replaceOpVariable($from, $to, $op);
        }
        $from->usages = [];
        $from->ops = [];
    }"""

cfgwalk_pat = re.compile(
    r'(?P<hdr>/\*\* Legacy whole-CFG replacement.*?\*/\s*)?'
    r'private function replaceVariablesByCfgWalk\(Operand \$from, Operand \$to, Block \$block\)\s*\{',
    re.DOTALL,
)
matches = list(cfgwalk_pat.finditer(text))
if len(matches) > 1:
    keep_idx = None
    for i, m in enumerate(matches):
        start = m.end()
        end = matches[i + 1].start() if i + 1 < len(matches) else len(text)
        body = text[start:end]
        if '$toReplace = new \\SplObjectStorage()' in body:
            keep_idx = i
            break
    if keep_idx is None:
        sys.stderr.write('php-cfg-simplifier-use-chain: no canonical replaceVariablesByCfgWalk found\n')
        raise SystemExit(1)
    for i in reversed(range(len(matches))):
        if i == keep_idx:
            continue
        m = matches[i]
        start = m.start()
        end = matches[i + 1].start() if i + 1 < len(matches) else len(text)
        # Drop through closing brace of this duplicate method.
        depth = 0
        j = m.end() - 1
        while j < len(text):
            if text[j] == '{':
                depth += 1
            elif text[j] == '}':
                depth -= 1
                if depth == 0:
                    end = j + 1
                    break
            j += 1
        text = text[:start] + text[end:]
    matches = list(cfgwalk_pat.finditer(text))

repl_sig = re.compile(
    r'    private function replaceVariables\(Operand \$from, Operand \$to, Block \$block\)\s*\{',
)

def method_extent(src: str, sig_start: int):
    brace = src.find('{', sig_start)
    if brace < 0:
        return None
    depth = 0
    j = brace
    while j < len(src):
        if src[j] == '{':
            depth += 1
        elif src[j] == '}':
            depth -= 1
            if depth == 0:
                return sig_start, j + 1
        j += 1
    return None

if "getenv('PHPCFG_SIMPLIFIER_LEGACY')" not in text:
    if not repl_sig.search(text):
        sys.stderr.write('php-cfg-simplifier-use-chain: replaceVariables anchor not found\n')
        raise SystemExit(1)
    if not list(cfgwalk_pat.finditer(text)):
        # Pristine vendor: the CFG walk lives inside replaceVariables — rename, do not delete (#36377).
        m = repl_sig.search(text)
        extent = method_extent(text, m.start())
        if extent is None:
            sys.stderr.write('php-cfg-simplifier-use-chain: could not parse replaceVariables body\n')
            raise SystemExit(1)
        start, end = extent
        old_method = text[start:end]
        cfgwalk_method = (
            '    /** Legacy whole-CFG replacement (PHPCFG_SIMPLIFIER_LEGACY=1 or USECHAIN=0). */\n'
            + old_method.replace(
                'private function replaceVariables',
                'private function replaceVariablesByCfgWalk',
                1,
            )
        )
        text = text[:start] + new_replace_variables + '\n\n' + cfgwalk_method + text[end:]
    else:
        # July-5 partial: cfgwalk exists; flip replaceVariables only.
        repl_pat = re.compile(
            r'    private function replaceVariables\(Operand \$from, Operand \$to, Block \$block\)\s*\{.*?\n    \}',
            re.DOTALL,
        )
        if not repl_pat.search(text):
            sys.stderr.write('php-cfg-simplifier-use-chain: replaceVariables anchor not found\n')
            raise SystemExit(1)
        # Lambda replacement: new_replace_variables contains Op\Phi — re.sub treats \P as an escape.
        text = repl_pat.sub(lambda _: new_replace_variables, text, count=1)

if len(list(cfgwalk_pat.finditer(text))) != 1:
    sys.stderr.write('php-cfg-simplifier-use-chain: expected exactly one replaceVariablesByCfgWalk\n')
    raise SystemExit(1)
if "getenv('PHPCFG_SIMPLIFIER_LEGACY')" not in text:
    sys.stderr.write('php-cfg-simplifier-use-chain: flip did not apply\n')
    raise SystemExit(1)

path.write_text(text)
PY
  echo "Applied php-cfg-simplifier-use-chain.patch (overlay)"
}

apply_php_cfg_simplifier_call_unpack_overlay() {
  local simplifier="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Visitor/Simplifier.php"
  if grep -q 'preserveCallSiteOperandMetadata' "$simplifier" 2>/dev/null; then
    echo "Skip php-cfg-simplifier-call-unpack.patch (already applied)"
    return 0
  fi
  python3 - "$simplifier" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = """                    if ($value === $from) {
                        $new[$key] = $to;
                        if ($op->isWriteVariable($name)) {
                            $to->addWriteOp($op);
                        } else {
                            $to->addUsage($op);
                        }
                    } else {
                        $new[$key] = $value;
                    }
                }
                $op->{$name} = $new;
            } elseif ($op->{$name} === $from) {
                $op->{$name} = $to;
                if ($op->isWriteVariable($name)) {
                    $to->addWriteOp($op);
                } else {
                    $to->addUsage($op);
                }
            }
        }
    }
}"""
new = """                    if ($value === $from) {
                        $new[$key] = $to;
                        $this->preserveCallSiteOperandMetadata($from, $to);
                        if ($op->isWriteVariable($name)) {
                            $to->addWriteOp($op);
                        } else {
                            $to->addUsage($op);
                        }
                    } else {
                        $new[$key] = $value;
                    }
                }
                $op->{$name} = $new;
            } elseif ($op->{$name} === $from) {
                $op->{$name} = $to;
                $this->preserveCallSiteOperandMetadata($from, $to);
                if ($op->isWriteVariable($name)) {
                    $to->addWriteOp($op);
                } else {
                    $to->addUsage($op);
                }
            }
        }
    }

    /** Keep named-call metadata when SSA simplifier replaces operands (#4321, #6838). */
    private function preserveCallSiteOperandMetadata(Operand $from, Operand $to): void
    {
        if (property_exists($from, 'callArgName') && null !== $from->callArgName) {
            $to->callArgName = $from->callArgName;
        }
    }
}"""
if old not in text:
    sys.stderr.write("php-cfg-simplifier-call-unpack: Simplifier.php anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-cfg-simplifier-call-unpack.patch (overlay)"
}

apply_php_cfg_magic_script_const_overlay() {
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/MagicScriptConst.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay_op="$PATCH_DIR/overlays/php-cfg/Op/Expr/MagicScriptConst.php"
  if grep -q 'MagicScriptConst::KIND_LINE' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-magic-script-const.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay_op" ]]; then
    echo "Skip php-cfg-magic-script-const.patch (overlay missing)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay_op" "$op"
  python3 - "$parser" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
replacements = [
    (
        "            case 'Scalar_MagicConst_Dir':\n"
        "                return new Literal(dirname($this->fileName));",
        "            case 'Scalar_MagicConst_Dir':\n"
        "                $op = new Op\\Expr\\MagicScriptConst(Op\\Expr\\MagicScriptConst::KIND_DIR, $this->mapAttributes($scalar));\n"
        "                $this->block->children[] = $op;\n"
        "                return $op->result;",
    ),
    (
        "            case 'Scalar_MagicConst_File':\n"
        "                return new Literal($this->fileName);",
        "            case 'Scalar_MagicConst_File':\n"
        "                $op = new Op\\Expr\\MagicScriptConst(Op\\Expr\\MagicScriptConst::KIND_FILE, $this->mapAttributes($scalar));\n"
        "                $this->block->children[] = $op;\n"
        "                return $op->result;",
    ),
    (
        "            case 'Scalar_MagicConst_File':\n"
        "                $op = new Op\\Expr\\MagicScriptConst(Op\\Expr\\MagicScriptConst::KIND_FILE, $this->mapAttributes($scalar));\n"
        "                $this->block->children[] = $op;\n"
        "                return $op->result;\n"
        "            case 'Scalar_MagicConst_Namespace':",
        "            case 'Scalar_MagicConst_File':\n"
        "                $op = new Op\\Expr\\MagicScriptConst(Op\\Expr\\MagicScriptConst::KIND_FILE, $this->mapAttributes($scalar));\n"
        "                $this->block->children[] = $op;\n"
        "                return $op->result;\n"
        "            case 'Scalar_MagicConst_Line':\n"
        "                $op = new Op\\Expr\\MagicScriptConst(Op\\Expr\\MagicScriptConst::KIND_LINE, $this->mapAttributes($scalar));\n"
        "                $this->block->children[] = $op;\n"
        "                return $op->result;\n"
        "            case 'Scalar_MagicConst_Namespace':",
    ),
]
applied = False
for old, new in replacements:
    if old in text:
        text = text.replace(old, new, 1)
        applied = True
if not applied:
    sys.stderr.write("php-cfg-magic-script-const: Parser.php anchor not found\n")
    raise SystemExit(1)
parser_path.write_text(text)
print("Applied php-cfg-magic-script-const.patch (overlay)")
PY
}

apply_php_cfg_declare_ticks_overlay() {
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/SetTickInterval.php"
  local leave_op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/LeaveTickInterval.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay_op="$PATCH_DIR/overlays/php-cfg/Op/Terminal/SetTickInterval.php"
  local overlay_leave="$PATCH_DIR/overlays/php-cfg/Op/Terminal/LeaveTickInterval.php"
  if grep -q 'LeaveTickInterval' "$parser" 2>/dev/null \
    && grep -q 'node->stmts' "$parser" 2>/dev/null \
    && [[ -f "$op" ]] && [[ -f "$leave_op" ]] \
    && grep -q 'public bool \$scoped' "$op" 2>/dev/null; then
    echo "Skip php-cfg-declare-ticks.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay_op" ]] || [[ ! -f "$overlay_leave" ]]; then
    echo "Skip php-cfg-declare-ticks.patch (overlay missing)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay_op" "$op"
  cp "$overlay_leave" "$leave_op"
  python3 - "$parser" <<'PY'
import re
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
new_method = '''    protected function parseStmt_Declare(Stmt\\Declare_ $node)
    {
        if (null === $this->currentFunc) {
            return;
        }
        $tickInterval = null;
        foreach ($node->declares as $item) {
            $key = $item->key->toLowerString();
            if ('ticks' === $key && $item->value instanceof Node\\Scalar\\LNumber) {
                $tickInterval = max(0, (int) $item->value->value);
                continue;
            }
            if ('strict_types' !== $key) {
                continue;
            }
            if ($item->value instanceof Node\\Scalar\\LNumber) {
                $this->currentFunc->strictTypes = 1 === $item->value->value;
            }
        }
        $braced = null !== $node->stmts;
        if (null !== $tickInterval) {
            $this->block->children[] = new Op\\Terminal\\SetTickInterval(
                $tickInterval,
                $this->mapAttributes($node),
                $braced
            );
        }
        if ($braced) {
            $this->block = $this->parseNodes($node->stmts, $this->block);
            if (null !== $tickInterval) {
                $this->block->children[] = new Op\\Terminal\\LeaveTickInterval(
                    $this->mapAttributes($node)
                );
            }
        }
    }
'''
pattern = re.compile(
    r'    protected function parseStmt_Declare\(Stmt\\Declare_ \$node\)\s*\{.*?\n    \}\n\n    protected function parseStmt_Do',
    re.S,
)
replacement = new_method + '\n    protected function parseStmt_Do'
match = pattern.search(text)
if not match:
    sys.stderr.write("php-cfg-declare-ticks: parseStmt_Declare anchor not found\n")
    raise SystemExit(1)
parser_path.write_text(text[:match.start()] + replacement + text[match.end():])
print("Applied php-cfg-declare-ticks.patch (overlay)")
PY
}

# Mark for-loop increment exprs so declare(ticks) skips them (Zend cadence, #23486).
apply_php_cfg_for_loop_increment_ticks_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if [[ ! -f "$parser" ]]; then
    return 0
  fi
  if grep -q "for_loop_increment" "$parser" 2>/dev/null; then
    echo "Skip php-cfg-for-loop-increment-ticks.patch (already applied)"
    return 0
  fi
  python3 - "$parser" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
old = """        $this->parseExprList($node->loop, self::MODE_READ);
        $this->block->children[] = new Jump($loopInit, $this->mapAttributes($node));
        $loopInit->addParent($this->block);
        $this->block = $loopEnd;
    }

    protected function parseStmt_Foreach(Stmt\\Foreach_ $node)"""
new = """        // Mark for-loop increment exprs — Zend does not tick them as statements (#23486).
        $incrStart = \\count($this->block->children);
        $this->parseExprList($node->loop, self::MODE_READ);
        for ($i = $incrStart, $c = \\count($this->block->children); $i < $c; ++$i) {
            $forLoopIncrement = true;
            $this->block->children[$i]->setAttribute('for_loop_increment', $forLoopIncrement);
        }
        $this->block->children[] = new Jump($loopInit, $this->mapAttributes($node));
        $loopInit->addParent($this->block);
        $this->block = $loopEnd;
    }

    protected function parseStmt_Foreach(Stmt\\Foreach_ $node)"""
old = old.replace("\\\\", "\\")
new = new.replace("\\\\", "\\")
if old not in text:
    sys.stderr.write("php-cfg-for-loop-increment-ticks: parseStmt_For anchor not found\n")
    raise SystemExit(1)
parser_path.write_text(text.replace(old, new, 1))
print("Applied php-cfg-for-loop-increment-ticks.patch (overlay)")
PY
}

# Zend post-loop ZEND_TICKS on while/for/do-while exit + skip for-init ticks (#25621).
apply_php_cfg_loop_exit_tick_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if [[ ! -f "$parser" ]]; then
    return 0
  fi
  if grep -q "zend_loop_exit_tick" "$parser" 2>/dev/null; then
    echo "Skip php-cfg-loop-exit-tick.patch (already applied)"
    return 0
  fi
  python3 - "$parser" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()

# while
old_w = """        $cond = $this->readVariable($this->parseExprNode($node->cond));

        $this->block->children[] = new JumpIf($cond, $loopBody, $loopEnd, $this->mapAttributes($node));
        $this->processAssertions($cond, $loopBody, $loopEnd);
        $loopBody->addParent($this->block);
        $loopEnd->addParent($this->block);

        $loopId = ++$this->ctx->gotoScopeId;
        $this->ctx->gotoLoopSwitchStack[] = $loopId;
        try {
            $this->block = $this->parseNodes($node->stmts, $loopBody);
        } finally {
            array_pop($this->ctx->gotoLoopSwitchStack);
        }
        $this->block->children[] = new Jump($loopInit, $this->mapAttributes($node));
        $loopInit->addParent($this->block);
        $this->block = $loopEnd;
    }

    /**
     * @param Node[] $expr"""
new_w = """        $cond = $this->readVariable($this->parseExprNode($node->cond));

        // Zend emits ZEND_TICKS after the while statement (on loop exit) (#25621).
        $jumpIf = new JumpIf($cond, $loopBody, $loopEnd, $this->mapAttributes($node));
        $zendLoopExitTick = true;
        $jumpIf->setAttribute('zend_loop_exit_tick', $zendLoopExitTick);
        $this->block->children[] = $jumpIf;
        $this->processAssertions($cond, $loopBody, $loopEnd);
        $loopBody->addParent($this->block);
        $loopEnd->addParent($this->block);

        $loopId = ++$this->ctx->gotoScopeId;
        $this->ctx->gotoLoopSwitchStack[] = $loopId;
        try {
            $this->block = $this->parseNodes($node->stmts, $loopBody);
        } finally {
            array_pop($this->ctx->gotoLoopSwitchStack);
        }
        $this->block->children[] = new Jump($loopInit, $this->mapAttributes($node));
        $loopInit->addParent($this->block);
        $this->block = $loopEnd;
    }

    /**
     * @param Node[] $expr"""
if old_w not in text:
    sys.stderr.write("php-cfg-loop-exit-tick: parseStmt_While anchor not found\\n")
    raise SystemExit(1)
text = text.replace(old_w, new_w, 1)

# do-while
old_d = """        $cond = $this->readVariable($this->parseExprNode($node->cond));
        $this->block->children[] = new JumpIf($cond, $loopBody, $loopEnd, $this->mapAttributes($node));
        $this->processAssertions($cond, $loopBody, $loopEnd);
        $loopBody->addParent($this->block);
        $loopEnd->addParent($this->block);

        $this->block = $loopEnd;
    }

    protected function parseStmt_Echo(Stmt\\Echo_ $node)"""
new_d = """        $cond = $this->readVariable($this->parseExprNode($node->cond));
        // Zend emits ZEND_TICKS after the do-while statement (on loop exit) (#25621).
        $jumpIf = new JumpIf($cond, $loopBody, $loopEnd, $this->mapAttributes($node));
        $zendLoopExitTick = true;
        $jumpIf->setAttribute('zend_loop_exit_tick', $zendLoopExitTick);
        $this->block->children[] = $jumpIf;
        $this->processAssertions($cond, $loopBody, $loopEnd);
        $loopBody->addParent($this->block);
        $loopEnd->addParent($this->block);

        $this->block = $loopEnd;
    }

    protected function parseStmt_Echo(Stmt\\Echo_ $node)"""
old_d = old_d.replace("\\\\", "\\")
new_d = new_d.replace("\\\\", "\\")
if old_d not in text:
    sys.stderr.write("php-cfg-loop-exit-tick: parseStmt_Do anchor not found\\n")
    raise SystemExit(1)
text = text.replace(old_d, new_d, 1)

# for: init mark + exit tick (must run after for_loop_increment overlay)
old_f = """    protected function parseStmt_For(Stmt\\For_ $node)
    {
        $this->parseExprList($node->init, self::MODE_READ);
        $loopInit = $this->block->create();
        $loopBody = $this->block->create();
        $loopEnd = $this->block->create();
        $this->block->children[] = new Jump($loopInit, $this->mapAttributes($node));
        $loopInit->addParent($this->block);
        $this->block = $loopInit;
        if (! empty($node->cond)) {
            $cond = $this->readVariable($this->parseExprNode($node->cond));
        } else {
            $cond = new Literal(true);
        }
        $this->block->children[] = new JumpIf($cond, $loopBody, $loopEnd, $this->mapAttributes($node));
        $this->processAssertions($cond, $loopBody, $loopEnd);"""
new_f = """    protected function parseStmt_For(Stmt\\For_ $node)
    {
        // Mark for-init exprs — Zend compile_expr_list does not emit statement ticks (#25621).
        $initStart = \\count($this->block->children);
        $this->parseExprList($node->init, self::MODE_READ);
        for ($i = $initStart, $c = \\count($this->block->children); $i < $c; ++$i) {
            $forLoopInit = true;
            $this->block->children[$i]->setAttribute('for_loop_init', $forLoopInit);
        }
        $loopInit = $this->block->create();
        $loopBody = $this->block->create();
        $loopEnd = $this->block->create();
        $this->block->children[] = new Jump($loopInit, $this->mapAttributes($node));
        $loopInit->addParent($this->block);
        $this->block = $loopInit;
        if (! empty($node->cond)) {
            $cond = $this->readVariable($this->parseExprNode($node->cond));
        } else {
            $cond = new Literal(true);
        }
        // Zend emits ZEND_TICKS after the for statement (on loop exit) (#25621).
        $jumpIf = new JumpIf($cond, $loopBody, $loopEnd, $this->mapAttributes($node));
        $zendLoopExitTick = true;
        $jumpIf->setAttribute('zend_loop_exit_tick', $zendLoopExitTick);
        $this->block->children[] = $jumpIf;
        $this->processAssertions($cond, $loopBody, $loopEnd);"""
# Prefer already-increment-patched for body (no bare parseExprList init)
old_f2 = """    protected function parseStmt_For(Stmt\\For_ $node)
    {
        $this->parseExprList($node->init, self::MODE_READ);
        $loopInit = $this->block->create();"""
# After for_loop_increment overlay, init line is still bare parseExprList
if old_f.replace("\\\\", "\\") in text:
    text = text.replace(old_f.replace("\\\\", "\\"), new_f.replace("\\\\", "\\"), 1)
else:
    sys.stderr.write("php-cfg-loop-exit-tick: parseStmt_For anchor not found\\n")
    raise SystemExit(1)

parser_path.write_text(text)
print("Applied php-cfg-loop-exit-tick.patch (overlay)")
PY
}

apply_php_cfg_magic_constants_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/MagicStringResolver.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/MagicStringResolver.php"
  if [[ ! -f "$overlay" ]]; then
    echo "Skip php-cfg-magic-constants.patch (overlay missing)" >&2
    return 1
  fi
  # Method uses methodStack (not functionStack); cmp covers full overlay identity (#36229).
  if patch_already_applied "$PATCH_DIR/php-cfg-magic-constants.patch" \
    && [[ -f "$target" ]] && cmp -s "$overlay" "$target"; then
    echo "Skip php-cfg-magic-constants.patch (already applied)"
    return 0
  fi
  install_overlay_file "$target" "$overlay" "php-cfg-magic-constants.patch (overlay)"
}

apply_php_cfg_anonymous_class_name_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if [[ ! -f "$target" ]]; then
    return 0
  fi
  if grep -q 'magicStringResolver->beginCompilationUnit' "$target" 2>/dev/null; then
    echo "Skip php-cfg-anonymous-class-name.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
original = text

if 'magicStringResolver->beginCompilationUnit' in text:
    print('Skip php-cfg-anonymous-class-name.patch (already applied)')
    raise SystemExit(0)

if 'protected $magicStringResolver' not in text:
    anchor = "    protected $astTraverser;\n\n    protected $fileName;"
    insert = (
        "    protected $astTraverser;\n\n"
        "    /** @var AstVisitor\\MagicStringResolver */\n"
        "    protected $magicStringResolver;\n\n"
        "    protected $fileName;"
    )
    if anchor in text:
        text = text.replace(anchor, insert, 1)
    else:
        anchor2 = "    protected $astTraverser;\n"
        if anchor2 not in text:
            sys.stderr.write('php-cfg-anonymous-class-name: astTraverser anchor not found\n')
            raise SystemExit(1)
        text = text.replace(
            anchor2,
            anchor2
            + "\n    /** @var AstVisitor\\MagicStringResolver */\n"
            + "    protected $magicStringResolver;\n",
            1,
        )

old_ctor = "        $this->astTraverser->addVisitor(new AstVisitor\\MagicStringResolver());"
new_ctor = (
    "        $this->magicStringResolver = new AstVisitor\\MagicStringResolver();\n"
    "        $this->astTraverser->addVisitor($this->magicStringResolver);"
)
if old_ctor in text:
    text = text.replace(old_ctor, new_ctor, 1)
elif '$this->magicStringResolver = new AstVisitor\\MagicStringResolver()' not in text:
    sys.stderr.write('php-cfg-anonymous-class-name: constructor anchor not found\n')
    raise SystemExit(1)

parse_ast_anchor = "        $this->fileName = $fileName;\n        $ast = $this->astTraverser->traverse($ast);"
parse_ast_insert = (
    "        $this->fileName = $fileName;\n"
    "        $this->magicStringResolver->beginCompilationUnit($fileName);\n"
    "        $ast = $this->astTraverser->traverse($ast);"
)
if parse_ast_anchor in text:
    text = text.replace(parse_ast_anchor, parse_ast_insert, 1)
else:
    sys.stderr.write('php-cfg-anonymous-class-name: parseAst anchor not found\n')
    raise SystemExit(1)

if text != original:
    parser_path.write_text(text)
    print('Applied php-cfg-anonymous-class-name.patch (overlay)')
raise SystemExit(0)
PY
}

apply_php_cfg_halt_compiler_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/HaltCompiler.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -q 'new Op\\Stmt\\HaltCompiler' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-halt-compiler.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay/Op/Stmt/HaltCompiler.php" || ! -f "$overlay/halt-compiler-parser-method.php" ]]; then
    echo "Skip php-cfg-halt-compiler.patch (overlay files missing)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay/Op/Stmt/HaltCompiler.php" "$op"
  python3 - "$parser" "$overlay/halt-compiler-parser-method.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
old = """    protected function parseStmt_HaltCompiler(Stmt\\HaltCompiler $node)
    {
        $this->block->children[] = new Op\\Terminal\\Echo_(
            $this->readVariable(new Operand\\Literal($node->remaining)),
            $this->mapAttributes($node)
        );
    }"""
new = method_path.read_text()
if old not in text:
    sys.stderr.write("php-cfg-halt-compiler: parseStmt_HaltCompiler stub not found in Parser.php\n")
    sys.exit(1)
parser_path.write_text(text.replace(old, new.rstrip("\n"), 1))
PY
  echo "Applied php-cfg-halt-compiler.patch (overlay)"
}

# __COMPILER_HALT_OFFSET__ magic constant + halt byte offset on Stmt\HaltCompiler (#5455).
apply_php_cfg_compiler_halt_offset_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local msc="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/MagicScriptConst.php"
  local halt="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/HaltCompiler.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -q 'strlen($this->sourceCode) - strlen($node->remaining)' "$parser" 2>/dev/null \
    && grep -q 'KIND_HALT_OFFSET' "$msc" 2>/dev/null \
    && grep -q 'haltOffset' "$halt" 2>/dev/null; then
    echo "Skip php-cfg-compiler-halt-offset overlay (already applied)"
    return 0
  fi
  if grep -q 'protected string $parseSourceCode' "$parser" 2>/dev/null \
    && grep -q 'parseSourceCode = $code' "$parser" 2>/dev/null \
    && grep -q 'KIND_HALT_OFFSET' "$msc" 2>/dev/null \
    && grep -q 'haltOffset' "$halt" 2>/dev/null; then
    echo "Skip php-cfg-compiler-halt-offset overlay (legacy parseSourceCode already applied)"
    return 0
  fi
  if [[ ! -f "$overlay/Op/Expr/MagicScriptConst.php" \
    || ! -f "$overlay/Op/Stmt/HaltCompiler.php" \
    || ! -f "$overlay/halt-compiler-parser-method.php" \
    || ! -f "$overlay/parser-compiler-halt-offset.php" ]]; then
    echo "Skip php-cfg-compiler-halt-offset overlay (files missing)" >&2
    return 1
  fi
  cp "$overlay/Op/Expr/MagicScriptConst.php" "$msc"
  cp "$overlay/Op/Stmt/HaltCompiler.php" "$halt"
  python3 - "$parser" "$overlay/halt-compiler-parser-method.php" "$overlay/parser-compiler-halt-offset.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
property_path = Path(sys.argv[3])
text = parser_path.read_text()
prop = property_path.read_text().rstrip("\n")
if "protected string $parseSourceCode" not in text and "protected $sourceCode" not in text:
    anchor = "    protected $anonId = 0;\n"
    if anchor not in text:
        sys.stderr.write("php-cfg-compiler-halt-offset: Parser anonId anchor not found\n")
        raise SystemExit(1)
    text = text.replace(anchor, anchor + "\n" + prop + "\n", 1)
old_parse = """    public function parse($code, $fileName)
    {
        return $this->parseAst($this->astParser->parse($code), $fileName);
    }"""
new_parse = """    public function parse($code, $fileName)
    {
        $this->parseSourceCode = $code;

        return $this->parseAst($this->astParser->parse($code), $fileName);
    }"""
if old_parse in text:
    text = text.replace(old_parse, new_parse, 1)
elif "parseSourceCode = $code" not in text and "sourceCode = $code" not in text:
    sys.stderr.write("php-cfg-compiler-halt-offset: Parser::parse anchor not found\n")
    raise SystemExit(1)
old_halt = """    protected function parseStmt_HaltCompiler(Stmt\\HaltCompiler $node)
    {
        $attrs = $this->mapAttributes($node);
        $this->block->children[] = new Op\\Stmt\\HaltCompiler(
            $node->remaining,
            $attrs
        );
        $this->block = new Block();
        $this->block->dead = true;
    }"""
new_halt = method_path.read_text().rstrip("\n")
if old_halt in text:
    text = text.replace(old_halt, new_halt, 1)
elif new_halt not in text:
    sys.stderr.write("php-cfg-compiler-halt-offset: parseStmt_HaltCompiler anchor not found\n")
    raise SystemExit(1)
# Upgrade: #6549 sourceCode field replaced legacy parseSourceCode (#16790).
text = text.replace("$this->parseSourceCode", "$this->sourceCode")
const_anchor = """    protected function parseExpr_ConstFetch(Expr\\ConstFetch $expr)
    {
        if ($expr->name->isUnqualified()) {"""
const_new = """    protected function parseExpr_ConstFetch(Expr\\ConstFetch $expr)
    {
        $lcConstName = strtolower(ltrim($expr->name->toString(), '\\\\'));
        if ('__compiler_halt_offset__' === $lcConstName) {
            $op = new Op\\Expr\\MagicScriptConst(
                Op\\Expr\\MagicScriptConst::KIND_HALT_OFFSET,
                $this->mapAttributes($expr)
            );
            $this->block->children[] = $op;

            return $op->result;
        }

        if ($expr->name->isUnqualified()) {"""
if const_anchor not in text:
    sys.stderr.write("php-cfg-compiler-halt-offset: parseExpr_ConstFetch anchor not found\n")
    raise SystemExit(1)
text = text.replace(const_anchor, const_new, 1)
# Upgrade path when the in-switch case was applied on a prior run.
text = text.replace(
    """                case '__compiler_halt_offset__':
                    $op = new Op\\Expr\\MagicScriptConst(
                        Op\\Expr\\MagicScriptConst::KIND_HALT_OFFSET,
                        $this->mapAttributes($expr)
                    );
                    $this->block->children[] = $op;

                    return $op->result;
""",
    "",
    1,
)
parser_path.write_text(text)
PY
  echo "Applied php-cfg-compiler-halt-offset overlay (#5455)"
}

apply_php_types_compiler_halt_offset_overlay() {
  local vendor_target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local prelinked_target="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local -a targets=("$vendor_target")
  if [[ -f "$prelinked_target" ]]; then
    targets+=("$prelinked_target")
  fi

  python3 - "${targets[@]}" <<'PY'
import sys
import re
from pathlib import Path

def patch_one(path: Path) -> bool:
    text = path.read_text()

    if "KIND_HALT_OFFSET" in text:
        return False
    if "MagicScriptConst::KIND_LINE" not in text:
        return False

    # Try an exact-string replacement first (fast path). Both operand orders appear in vendor trees.
    old_variants = [
        """                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }""",
        """                if ($op->kind === \\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE) {
                    return [Type::int()];
                }""",
    ]
    new = """                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind
                    || \\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_HALT_OFFSET === $op->kind) {
                    return [Type::int()];
                }"""
    for old in old_variants:
        if old in text:
            path.write_text(text.replace(old, new, 1))
            return True

    # Anchor drift: match the KIND_LINE guard even if formatting/spacing/operand order differs.
    pattern = re.compile(
        r"(?P<indent>[ \t]+)if\s*\(\s*(?:"
        r"\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE\s*===\s*\\$op->kind"
        r"|\\$op->kind\s*===\s*\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE"
        r")\s*\)\s*\{\s*\n"
        r"(?P=indent)[ \t]+return\s*\\[Type::int\\(\\)\\];\s*\n"
        r"(?P=indent)\\}",
        re.MULTILINE,
    )
    m = pattern.search(text)
    if not m:
        raise RuntimeError("php-types-compiler-halt-offset: TypeReconstructor anchor not found")

    indent = m.group("indent")
    replacement = (
        f"{indent}if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind\n"
        f"{indent}    || \\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_HALT_OFFSET === $op->kind) {{\n"
        f"{indent}    return [Type::int()];\n"
        f"{indent}}}"
    )
    path.write_text(text[: m.start()] + replacement + text[m.end() :])
    return True


modified_any = False
for arg in sys.argv[1:]:
    p = Path(arg)
    if not p.exists():
        continue
    try:
        modified_any = patch_one(p) or modified_any
    except RuntimeError as e:
        sys.stderr.write(str(e) + "\n")
        raise SystemExit(1)

if modified_any:
    sys.stderr.write("php-types-compiler-halt-offset: patched TypeReconstructor\n")
PY

  if grep -q 'KIND_HALT_OFFSET' "$vendor_target" 2>/dev/null; then
    echo "Skip php-types-compiler-halt-offset overlay (already applied)"
  else
    echo "php-types-compiler-halt-offset overlay failed: KIND_HALT_OFFSET missing after repair (#5455)" >&2
    return 1
  fi
}

# Per-property MODIFIER_READONLY: Property.propertyFlags + Parser assignment (#3149, #4230).
apply_php_cfg_readonly_function_overlay() {
  local func="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Func.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if [[ ! -f "$func" || ! -f "$parser" ]]; then
    echo "Skip php-cfg-readonly-function.patch (vendor php-cfg missing)" >&2
    return 1
  fi
  if patch_already_applied "$PATCH_DIR/php-cfg-readonly-function.patch"; then
    echo "Skip php-cfg-readonly-function.patch (already applied)"
    return 0
  fi
  python3 - "$func" "$parser" <<'PY'
import sys
from pathlib import Path

func_path = Path(sys.argv[1])
parser_path = Path(sys.argv[2])

func_text = func_path.read_text()
if "const FLAG_READONLY = 0x100;" not in func_text:
    needle = "    const FLAG_CLOSURE = 0x80;\n"
    insert = needle + "\n    const FLAG_READONLY = 0x100;\n"
    if needle not in func_text:
        sys.stderr.write("php-cfg-readonly-function: Func.php FLAG_CLOSURE anchor missing\n")
        raise SystemExit(1)
    func_text = func_text.replace(needle, insert, 1)
    func_path.write_text(func_text)

parser_text = parser_path.read_text()

stmt_old = """        $this->script->functions[] = $func = new Func(
            $node->namespacedName->toString(),
            $node->byRef ? Func::FLAG_RETURNS_REF : 0,"""
stmt_new = """        $this->script->functions[] = $func = new Func(
            $node->namespacedName->toString(),
            ($node->byRef ? Func::FLAG_RETURNS_REF : 0)
                | ($node->getAttribute('compilerReadonlyFunction', false) ? Func::FLAG_READONLY : 0),"""
if stmt_new not in parser_text:
    if stmt_old not in parser_text:
        sys.stderr.write("php-cfg-readonly-function: parseStmt_Function anchor missing\n")
        raise SystemExit(1)
    parser_text = parser_text.replace(stmt_old, stmt_new, 1)

readonly_block = """        if ($expr->getAttribute('compilerReadonlyFunction', false)) {
            $flags |= Func::FLAG_READONLY;
        }
"""

def inject_closure_readonly(text: str, method: str) -> str:
    marker = f"protected function {method}"
    start = text.find(marker)
    if start == -1:
        return text
    rest = text[start:]
    next_fn = rest.find("\n    protected function ", len(marker))
    method_body = rest if next_fn == -1 else rest[:next_fn]
    if "compilerReadonlyFunction" in method_body:
        return text
    anchor = "        $flags |= $expr->static ? Func::FLAG_STATIC : 0;\n"
    if anchor not in method_body:
        sys.stderr.write(f"php-cfg-readonly-function: {method} static flags anchor missing\n")
        raise SystemExit(1)
    new_method_body = method_body.replace(anchor, anchor + "\n" + readonly_block, 1)
    return text[:start] + new_method_body + text[start + len(method_body):]

parser_text = inject_closure_readonly(parser_text, "parseExpr_Closure")
if "function parseExpr_ArrowFunction" in parser_text:
    parser_text = inject_closure_readonly(parser_text, "parseExpr_ArrowFunction")

if "const FLAG_READONLY = 0x100;" not in func_path.read_text():
    sys.stderr.write("php-cfg-readonly-function: Func.php FLAG_READONLY missing after overlay\n")
    raise SystemExit(1)
closure_slice = parser_text.split("parseExpr_Closure", 1)[1].split("protected function", 1)[0]
if "compilerReadonlyFunction" not in closure_slice:
    sys.stderr.write("php-cfg-readonly-function: parseExpr_Closure readonly wiring missing\n")
    raise SystemExit(1)
if "function parseExpr_ArrowFunction" in parser_text:
    arrow_slice = parser_text.split("parseExpr_ArrowFunction", 1)[1].split("protected function", 1)[0]
    if "compilerReadonlyFunction" not in arrow_slice:
        sys.stderr.write("php-cfg-readonly-function: parseExpr_ArrowFunction readonly wiring missing\n")
        raise SystemExit(1)

parser_path.write_text(parser_text)
PY
  echo "Applied php-cfg-readonly-function.patch (overlay)"
  return 0
}

apply_php_parser_final_property_overlay() {
  local vendor="$ROOT/vendor/nikic/php-parser/lib/PhpParser/ParserAbstract.php"
  local prelinked="$ROOT/prelinked/bootstrap-vendor/sources/nikic/php-parser/lib/PhpParser/ParserAbstract.php"
  local target
  for target in "$vendor" "$prelinked"; do
    [[ -f "$target" ]] || continue
    if grep -q 'PHP_COMPILER_FINAL_PROPERTY' "$target" 2>/dev/null; then
      echo "Skip php-parser-final-property.patch (already applied: ${target#$ROOT/})"
      continue
    fi
    python3 - "$target" <<'PY'
import sys
from pathlib import Path
path = Path(sys.argv[1])
text = path.read_text()
old = """        if ($node->flags & Class_::MODIFIER_FINAL) {
            $this->emitError(new Error('Properties cannot be declared final',
                $this->getAttributesAt($modifierPos)));
        }"""
new = """        // PHP_COMPILER_FINAL_PROPERTY: PHP 8.4+ allows final properties (#22241).
        // Compile gate: PHPCompiler\\CompilerVersion::supportsFinalProperties()."""
if old not in text:
    sys.stderr.write(f"php-parser-final-property: checkProperty final-block anchor missing in {path}\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
    echo "Applied php-parser-final-property.patch (${target#$ROOT/})"
  done
  return 0
}

apply_php_cfg_property_readonly_overlay() {
  local prop="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if [[ ! -f "$prop" || ! -f "$parser" ]]; then
    return 0
  fi
  if grep -qE 'propertyFlags = \$node->flags|\$cfgProp->readonly =|\$prop->readonly =|\$property->readonly =|->readonly = 0 !== \\(\\$node->flags & .*MODIFIER_READONLY\\)' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-property-readonly.patch (already applied)"
    return 0
  fi
  if ! grep -q 'propertyFlags' "$prop" 2>/dev/null && ! grep -q 'public $readonly' "$prop" 2>/dev/null; then
    python3 - "$prop" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if 'propertyFlags' in text or 'public $readonly' in text:
    raise SystemExit(0)
needle = "    public $declaredType;\n"
insert = (
    needle
    + "\n"
    + "    /** php-parser Stmt\\Property flags (includes MODIFIER_READONLY, issue #3149). */\n"
    + "    public int $propertyFlags = 0;\n"
)
if needle not in text:
    sys.stderr.write("php-cfg-property-readonly: Property.php declaredType anchor missing\n")
    raise SystemExit(1)
path.write_text(text.replace(needle, insert, 1))
PY
    echo "Applied php-cfg-property-readonly.patch (Property overlay)"
  fi
  python3 - "$parser" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if re.search(r'propertyFlags\s*=\s*\$node->flags', text):
    raise SystemExit(0)
if re.search(r'\$(cfgProp|prop)->readonly\s*=', text):
    raise SystemExit(0)
assign = "            $prop->propertyFlags = $node->flags;\n"
needle = "            $this->block->children[] = $prop;\n"
if needle in text and assign not in text:
    path.write_text(text.replace(needle, assign + needle, 1))
    raise SystemExit(0)
inline = re.compile(
    r"(\s+)\$this->block->children\[\] = new Op\\Stmt\\Property\(\n"
    r"([\s\S]*?)\n\1\);\n",
    re.M,
)
match = inline.search(text)
if match:
    indent = match.group(1)
    inner = match.group(2)
    replacement = (
        f"{indent}$prop = new Op\\Stmt\\Property(\n"
        f"{inner}\n"
        f"{indent});\n"
        f"{assign}"
        f"{indent}$this->block->children[] = $prop;\n"
    )
    path.write_text(text[: match.start()] + replacement + text[match.end() :])
    raise SystemExit(0)
sys.stderr.write("php-cfg-property-readonly: parseStmt_Property insert anchor missing\n")
raise SystemExit(1)
PY
  echo "Applied php-cfg-property-readonly.patch (Parser overlay)"
  return 0
}

apply_php_cfg_throw_expr_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Throw_.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -q 'return new Op\\Expr\\Throw_' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-throw-expr.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay/Op/Expr/Throw_.php" || ! -f "$overlay/throw-expr-parser-method.php" ]]; then
    echo "Skip php-cfg-throw-expr.patch (overlay files missing)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay/Op/Expr/Throw_.php" "$op"
  python3 - "$parser" "$overlay/throw-expr-parser-method.php" <<'PY'
import re
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
new = method_path.read_text().rstrip("\n")
terminal = re.compile(
    r"    protected function parseExpr_Throw\(Expr\\Throw_ \$expr\)\s*\{"
    r".*?\n    \}\n",
    re.S,
)
if "return new Op\\Expr\\Throw_" in text:
    sys.exit(0)
match = terminal.search(text)
if match:
    parser_path.write_text(text[: match.start()] + new + "\n" + text[match.end() :])
else:
    anchor = "    protected function parseStmt_Trait(Stmt\\Trait_ $node)"
    if anchor not in text:
        sys.stderr.write("php-cfg-throw-expr: insert anchor not found in Parser.php\n")
        sys.exit(1)
    parser_path.write_text(text.replace(anchor, new + "\n" + anchor, 1))
PY
  echo "Applied php-cfg-throw-expr.patch (overlay)"
}

apply_php_cfg_trycatch_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/TryCatch.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if [[ ! -f "$overlay/Op/Stmt/TryCatch.php" || ! -f "$overlay/trycatch-parser-method.php" ]]; then
    echo "Skip php-cfg-trycatch.patch (overlay files missing)" >&2
    return 1
  fi
  if grep -q 'new Op\\Stmt\\TryCatch' "$parser" 2>/dev/null; then
    if grep -q '\$elseBlock ?? \$endBlock' "$parser" 2>/dev/null \
      && grep -q 'public \$else;' "$op" 2>/dev/null \
      && grep -q 'CatchIntersectionSupport::ATTRIBUTE' "$parser" 2>/dev/null; then
      echo "Skip php-cfg-trycatch.patch (already applied)"
      return 0
    fi
    mkdir -p "$(dirname "$op")"
    cp "$overlay/Op/Stmt/TryCatch.php" "$op"
    python3 - "$parser" "$overlay/trycatch-parser-method.php" <<'PY'
import re, sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
new = method_path.read_text().rstrip("\n")
text = parser_path.read_text()
pat = r'    protected function parseStmt_TryCatch\(Stmt\\TryCatch \$node\)\s*\{.*?\n    \}\n\n    protected function parseStmt_Unset'
m = re.search(pat, text, re.DOTALL)
if not m:
    sys.stderr.write("php-cfg-trycatch: parseStmt_TryCatch block not found for refresh\n")
    sys.exit(1)
parser_path.write_text(text[:m.start()] + new + "\n\n    protected function parseStmt_Unset" + text[m.end():])
PY
    echo "Refreshed php-cfg-trycatch.patch (try/catch/else + catch intersection #28205)"
    return 0
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay/Op/Stmt/TryCatch.php" "$op"
  python3 - "$parser" "$overlay/trycatch-parser-method.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
old = """    protected function parseStmt_TryCatch(Stmt\\TryCatch $node)
    {
        // TODO: implement this!!!
    }"""
new = method_path.read_text()
if old not in text:
    sys.stderr.write("php-cfg-trycatch: parseStmt_TryCatch stub not found in Parser.php\n")
    sys.exit(1)
parser_path.write_text(text.replace(old, new.rstrip("\n"), 1))
PY
  echo "Applied php-cfg-trycatch.patch (overlay)"
}
