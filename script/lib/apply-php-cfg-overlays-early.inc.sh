# Sourced from script/apply-patches.sh — early php-cfg overlays (#36403 size ratchet).
# Requires ROOT, PATCH_DIR, and helpers: install_overlay_file, patch_already_applied.
# shellcheck shell=bash

# Class parseStmt_Class also uses parseExprList($node->implements); scope checks to parseStmt_Enum (#3083, #3419).
php_cfg_enum_implements_parser_applied() {
  local parser="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php}"
  [[ -f "$parser" ]] || return 1
  # parseStmt_Enum body is >30 lines once ClassMethod/TraitUse/ClassConst arms land (#36229).
  grep -A60 'function parseStmt_Enum' "$parser" 2>/dev/null \
    | grep -q 'parseExprList($node->implements)'
}

apply_php_cfg_match_overlay() {
  local overlay="$ROOT/patches/overlays/php-cfg/match-parser-methods.php"
  if [[ ! -f "$overlay" ]]; then
    echo "Skip php-cfg-match.patch (overlay missing)" >&2
    return 1
  fi
  # Always run — patch-php-cfg-match.py refreshes stale is_object probes (#7263, #7199).
  if python3 "$ROOT/script/patch-php-cfg-match.py"; then
    if patch_already_applied "$PATCH_DIR/php-cfg-match.patch"; then
      echo "Refreshed php-cfg-match.patch (overlay)"
    else
      echo "Applied php-cfg-match.patch (overlay)"
    fi
    return 0
  fi
  return 1
}

apply_php_cfg_property_type_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php"
  if patch_already_applied "$PATCH_DIR/php-cfg-property-type.patch"; then
    echo "Skip php-cfg-property-type.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
original = text
if "public $type;" not in text:
    text = text.replace(
        "    public $visibility;\n\n    public $static;",
        "    public $visibility;\n\n    /** Inferred type from php-types TypeReconstructor (bootstrap native AOT needs declared field). */\n    public $type;\n\n    public $static;",
        1,
    )
text = text.replace("int $visiblity,", "int $visibility,", 1)
text = text.replace("$this->visiblity = $visiblity;", "$this->visibility = $visibility;", 1)
if "public $type;" not in text or "int $visibility," not in text:
    sys.stderr.write("php-cfg-property-type: Property.php overlay anchors not found\n")
    raise SystemExit(1)
if text != original:
    path.write_text(text)
    print("Applied php-cfg-property-type.patch (overlay)")
else:
    print("Skip php-cfg-property-type.patch (already applied)")
PY
}

apply_php_cfg_assignop_coalesce_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if patch_already_applied "$PATCH_DIR/php-cfg-assignop-coalesce.patch"; then
    echo "Skip php-cfg-assignop-coalesce.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
needle = "                'Expr_AssignOp_Pow' => Op\\Expr\\BinaryOp\\Pow::class,\n"
insert = needle + "                'Expr_AssignOp_Coalesce' => Op\\Expr\\BinaryOp\\Coalesce::class,\n"
if "'Expr_AssignOp_Coalesce'" in text:
    raise SystemExit(0)
if needle not in text:
    sys.stderr.write("php-cfg-assignop-coalesce: Parser.php AssignOp_Pow anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(needle, insert, 1))
PY
  echo "Applied php-cfg-assignop-coalesce.patch (overlay)"
}

apply_php_cfg_loop_resolver_continue_switch_warning_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/LoopResolver.php"
  if grep -q 'continue %d' "$target" 2>/dev/null; then
    echo "Skip php-cfg-loop-resolver-continue-switch-warning level overlay (already applied)"
    return 0
  fi
  if ! grep -q 'compiler_language_warning' "$target" 2>/dev/null; then
    return 1
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if 'continue %d' in text:
    raise SystemExit(0)
old = """    protected function makeContinueSwitchWarning(Node $node): Expression
    {
        $attrs = $node->getAttributes();
        $line = isset($attrs['startLine']) ? (int) $attrs['startLine'] : 0;
        $args = [
            new Arg(new String_('\"continue\" targeting switch is equivalent to \"break\"', $attrs)),
        ];
        if ($line > 0) {
            $args[] = new Arg(new LNumber($line, $attrs), false, false, $attrs);
        }

        return new Expression(
            new FuncCall(new Name('compiler_language_warning'), $args, $attrs),
            $attrs
        );
    }"""
new = """    protected function makeContinueSwitchWarning(Node $node): Expression
    {
        $attrs = $node->getAttributes();
        $line = isset($attrs['startLine']) ? (int) $attrs['startLine'] : 0;
        $level = 1;
        if ($node->num instanceof LNumber) {
            $level = $node->num->value;
        }
        $message = $level > 1
            ? \\sprintf('\"continue %d\" targeting switch is equivalent to \"break %d\"', $level, $level)
            : '\"continue\" targeting switch is equivalent to \"break\"';
        $args = [
            new Arg(new String_($message, $attrs)),
        ];
        if ($line > 0) {
            $args[] = new Arg(new LNumber($line, $attrs), false, false, $attrs);
        }

        return new Expression(
            new FuncCall(new Name('compiler_language_warning'), $args, $attrs),
            $attrs
        );
    }"""
if old not in text:
    sys.stderr.write("php-cfg-loop-resolver-continue-switch-warning: level-1 anchor not found in LoopResolver.php\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-cfg-loop-resolver-continue-switch-warning.patch (level overlay)"
}

apply_php_cfg_loop_resolver_break_continue_positive_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/LoopResolver.php"
  if grep -q "operator accepts only positive integers" "$target" 2>/dev/null; then
    echo "Skip php-cfg-loop-resolver-break-continue-positive overlay (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if "operator accepts only positive integers" in text:
    raise SystemExit(0)
old = """            $num = $node->num->value;
            if ($num < 1 || $num > \\count($stack)) {
                throw new \\LogicException('Too high of a count for '.$node->getType());
            }
"""
new = """            $num = $node->num->value;
            $keyword = 'Stmt_Break' === $node->getType() ? 'break' : 'continue';
            if ($num < 1) {
                throw new \\LogicException(sprintf(\"'%s' operator accepts only positive integers\", $keyword));
            }
            if ($num > \\count($stack)) {
                throw new \\LogicException(sprintf(\"Cannot '%s' %d levels\", $keyword, $num));
            }
"""
if old not in text:
    sys.stderr.write("php-cfg-loop-resolver-break-continue-positive: LoopResolver anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-cfg-loop-resolver-break-continue-positive.patch (overlay)"
}

apply_php_cfg_arrow_function_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/ArrowFunction.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -q 'function parseExpr_ArrowFunction' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-arrow-function.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay/Op/Expr/ArrowFunction.php" || ! -f "$overlay/arrow-function-parser-method.php" ]]; then
    echo "Skip php-cfg-arrow-function.patch (overlay files missing)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay/Op/Expr/ArrowFunction.php" "$op"
  python3 - "$parser" "$overlay/arrow-function-parser-method.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
anchor = """    protected function parseExpr_ClassConstFetch(Expr\\ClassConstFetch $expr)
    {
        $c = $this->readVariable($this->parseExprNode($expr->class));"""
insert = method_path.read_text().rstrip("\n") + "\n\n"
if anchor not in text:
    sys.stderr.write("php-cfg-arrow-function: parseExpr_ClassConstFetch anchor not found in Parser.php\n")
    sys.exit(1)
parser_path.write_text(text.replace(anchor, insert + anchor, 1))
PY
  echo "Applied php-cfg-arrow-function.patch (overlay)"
}

apply_php_cfg_process_assertions_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/assertion-parser-methods.php"
  if grep -q 'function processAssertions' "$parser" 2>/dev/null; then
    echo "Skip php-cfg processAssertions overlay (already applied)"
    return 0
  fi
  if [[ ! -f "$parser" || ! -f "$overlay" ]]; then
    echo "Skip php-cfg processAssertions overlay (files missing)" >&2
    return 1
  fi
  python3 - "$parser" "$overlay" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
if 'function processAssertions' in text:
    raise SystemExit(0)
anchor = "    protected function readAssertion(Assertion $assert)"
insert = method_path.read_text().rstrip("\n") + "\n\n"
if anchor not in text:
    sys.stderr.write("php-cfg-process-assertions: readAssertion anchor not found in Parser.php\n")
    raise SystemExit(1)
parser_path.write_text(text.replace(anchor, insert + anchor, 1))
PY
  echo "Applied php-cfg processAssertions overlay"
}

apply_php_cfg_trait_use_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/TraitUse.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if [[ ! -f "$parser" || ! -f "$overlay/trait-use-parser-method.php" || ! -f "$overlay/Op/Stmt/TraitUse.php" ]]; then
    echo "Skip php-cfg trait-use overlay (files missing)" >&2
    return 0
  fi
  if grep -q 'function parseStmt_TraitUse' "$parser" 2>/dev/null \
    && ! grep -A8 'function parseStmt_TraitUse' "$parser" 2>/dev/null | grep -q '// TODO'; then
    echo "Skip php-cfg trait-use overlay (already applied)"
    return 0
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay/Op/Stmt/TraitUse.php" "$op"
  python3 - "$parser" "$overlay/trait-use-parser-method.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
if 'function parseStmt_TraitUse' in text and '// TODO' not in text.split('function parseStmt_TraitUse', 1)[1].split('function ', 1)[0]:
    raise SystemExit(0)
old = """    protected function parseStmt_TraitUse(Stmt\\TraitUse $node)
    {
        // TODO
    }"""
new = method_path.read_text().rstrip("\n") + "\n"
if old not in text:
    sys.stderr.write("php-cfg-trait-use: parseStmt_TraitUse TODO stub not found in Parser.php\n")
    raise SystemExit(1)
parser_path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-cfg trait-use overlay (#7417)"
}

apply_php_cfg_yield_from_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/YieldFrom.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if patch_already_applied "$PATCH_DIR/php-cfg-yield-from.overlay"; then
    echo "Skip php-cfg yield-from overlay (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay/Op/Expr/YieldFrom.php" || ! -f "$overlay/yield-from-parser-method.php" ]]; then
    echo "Skip php-cfg yield-from overlay (overlay files missing)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay/Op/Expr/YieldFrom.php" "$op"
  python3 - "$parser" "$overlay/yield-from-parser-method.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()

if 'function parseExpr_YieldFrom' in text:
    parser_path.write_text(text)
    raise SystemExit(0)

anchor = """    protected function parseExpr_Yield(Expr\\Yield_ $expr)
    {"""
if anchor not in text:
    sys.stderr.write("php-cfg-yield-from: parseExpr_Yield anchor not found in Parser.php\n")
    raise SystemExit(1)

insert = method_path.read_text().rstrip("\n") + "\n\n"
parser_path.write_text(text.replace(anchor, insert + anchor, 1))
PY
  echo "Applied php-cfg yield-from overlay"
}

# Backtick shell-exec → shell_exec() FuncCall (upstream php-cfg + prelinked; #26280).
apply_php_cfg_shell_exec_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if patch_already_applied "$PATCH_DIR/php-cfg-shell-exec.overlay"; then
    echo "Skip php-cfg shell-exec overlay (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay/shell-exec-parser-method.php" ]]; then
    echo "Skip php-cfg shell-exec overlay (overlay files missing)" >&2
    return 1
  fi
  python3 - "$parser" "$overlay/shell-exec-parser-method.php" <<'PYINNER'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()

if 'function parseExpr_ShellExec' in text:
    raise SystemExit(0)

anchor = """    protected function parseExpr_YieldFrom(Expr\YieldFrom $expr)
    {
        $inner = $this->readVariable($this->parseExprNode($expr->expr));

        return new Op\Expr\YieldFrom($inner, $this->mapAttributes($expr));
    }
"""
if anchor not in text:
    sys.stderr.write("php-cfg-shell-exec: parseExpr_YieldFrom anchor not found in Parser.php\n")
    raise SystemExit(1)

insert = method_path.read_text().rstrip("\n") + "\n\n"
parser_path.write_text(text.replace(anchor, anchor + "\n" + insert, 1))
PYINNER
  echo "Applied php-cfg shell-exec overlay (#26280)"
}


# Vendor may ship promotionSetVisibility on Param without promotionFlags (#1492 partial vendor).
apply_php_cfg_ctor_promotion_overlay() {
  local param="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if [[ ! -f "$param" || ! -f "$parser" ]]; then
    return 0
  fi
  if patch_already_applied "$PATCH_DIR/php-cfg-ctor-promotion.patch"; then
    echo "Skip php-cfg-ctor-promotion.patch (already applied)"
    return 0
  fi
  python3 - "$param" "$parser" <<'PY'
import re
import sys
from pathlib import Path

param_path = Path(sys.argv[1])
parser_path = Path(sys.argv[2])
param_text = param_path.read_text()
parser_text = parser_path.read_text()

flags_field = (
    "\n    /** Constructor property promotion visibility (PhpParser Class_ flags), or 0. */\n"
    "    public $promotionFlags = 0;\n"
)
if "promotionFlags" not in param_text:
    for needle in (
        "    /** Constructor promotion: asymmetric set visibility (#3165). */\n",
        "    public int $promotionSetVisibility = 0;\n",
        "    public $promotionSetVisibility = 0;\n",
    ):
        if needle in param_text:
            param_text = param_text.replace(needle, flags_field + needle, 1)
            break
    else:
        needle = "    public $declaredType;\n\n    // A helper\n    public $function;"
        if needle in param_text:
            insert = (
                "    public $declaredType;\n"
                + flags_field
                + "\n    // A helper\n    public $function;"
            )
            param_text = param_text.replace(needle, insert, 1)
        else:
            sys.stderr.write("php-cfg-ctor-promotion: Param.php anchor missing\n")
            raise SystemExit(1)
    param_path.write_text(param_text)

flags_line = "            $p->promotionFlags = $param->flags & Stmt\\Class_::VISIBILITY_MODIFIER_MASK;\n"
if flags_line.strip() not in parser_text:
    inserted = False
    for needle in (
        "            $p->promotionReadonly = 0 !== ($param->flags & Stmt\\Class_::MODIFIER_READONLY);\n",
        "            $p->promotionSetVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($p->getAttributes());\n",
        "            $p->promotionGetVisibility = $this->extractAsymmetricGetVisibilityFromAttributes($p->getAttributes());\n",
        "            $p->result->original = new Operand\\Variable(new Operand\\Literal($p->name->value));\n",
    ):
        if needle in parser_text:
            parser_text = parser_text.replace(needle, flags_line + needle, 1)
            inserted = True
            break
    if not inserted:
        needle = (
            "            );\n"
            "            $p->result->original = new Operand\\Variable(new Operand\\Literal($p->name->value));"
        )
        if needle not in parser_text:
            sys.stderr.write("php-cfg-ctor-promotion: Parser.php parseParameterList anchor missing\n")
            raise SystemExit(1)
        parser_text = parser_text.replace(
            needle,
            "            );\n" + flags_line + "            $p->result->original = new Operand\\Variable(new Operand\\Literal($p->name->value));",
            1,
        )
    parser_path.write_text(parser_text)
PY
  echo "Applied php-cfg-ctor-promotion.patch (overlay)"
}

apply_php_cfg_ctor_promotion_readonly_overlay() {
  local param="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if [[ ! -f "$param" || ! -f "$parser" ]]; then
    return 0
  fi
  if patch_already_applied "$PATCH_DIR/php-cfg-ctor-promotion-readonly.patch"; then
    echo "Skip php-cfg-ctor-promotion-readonly.patch (already applied)"
    return 0
  fi
  python3 - "$param" "$parser" <<'PY'
import sys
from pathlib import Path

param_path = Path(sys.argv[1])
parser_path = Path(sys.argv[2])
param_text = param_path.read_text()
parser_text = parser_path.read_text()

readonly_field = (
    "\n    /** Constructor property promotion readonly (PhpParser Class_::MODIFIER_READONLY). */\n"
    "    public $promotionReadonly = false;\n"
)
if "promotionReadonly" not in param_text:
    for needle in (
        "    public $promotionFlags = 0;\n",
        "    public int $promotionFlags = 0;\n",
        "    /** Constructor promotion: asymmetric set visibility (#3165). */\n",
        "    public int $promotionSetVisibility = 0;\n",
    ):
        if needle in param_text:
            param_text = param_text.replace(needle, needle + readonly_field, 1)
            break
    else:
        sys.stderr.write("php-cfg-ctor-promotion-readonly: Param.php anchor missing\n")
        raise SystemExit(1)
    param_path.write_text(param_text)

readonly_line = "            $p->promotionReadonly = 0 !== ($param->flags & Stmt\\Class_::MODIFIER_READONLY);\n"
if readonly_line.strip() not in parser_text:
    flags_line = "            $p->promotionFlags = $param->flags & Stmt\\Class_::VISIBILITY_MODIFIER_MASK;\n"
    if flags_line not in parser_text:
        sys.stderr.write("php-cfg-ctor-promotion-readonly: Parser promotionFlags anchor missing\n")
        raise SystemExit(1)
    parser_text = parser_text.replace(flags_line, flags_line + readonly_line, 1)
    parser_path.write_text(parser_text)
PY
  echo "Applied php-cfg-ctor-promotion-readonly.patch (overlay)"
}

# Vendor may ship promotionSetVisibility on Param before Property gains setVisibility (#3165, #1492).
apply_php_cfg_asymmetric_visibility_overlay() {
  local prop="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php"
  local param="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php"
  if [[ ! -f "$prop" || ! -f "$param" ]]; then
    return 0
  fi
  local has_prop=0 has_asym_param=0
  if grep -q 'public int \$setVisibility' "$prop" 2>/dev/null; then
    has_prop=1
  fi
  if grep -q 'promotionSetVisibility' "$param" 2>/dev/null; then
    has_asym_param=1
  fi
  if [[ $has_prop -eq 1 && $has_asym_param -eq 1 ]]; then
    if ! grep -q 'public int \$getVisibility' "$prop" 2>/dev/null; then
      python3 - "$prop" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if 'public int $getVisibility' in text:
    raise SystemExit(0)
needle = "    public int $setVisibility = 0;\n"
insert = needle + "\n    /** PHP 8.4 asymmetric get visibility (0 = same as write; issue #5059). */\n    public int $getVisibility = 0;\n"
if needle not in text:
    sys.stderr.write("php-cfg-asymmetric-visibility: Property.php setVisibility anchor missing\n")
    raise SystemExit(1)
path.write_text(text.replace(needle, insert, 1))
PY
      echo "Applied php-cfg-asymmetric-visibility.patch (Property getVisibility overlay #5059)"
    fi
    if ! grep -q 'promotionGetVisibility' "$param" 2>/dev/null; then
      python3 - "$param" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if 'promotionGetVisibility' in text:
    raise SystemExit(0)
get_vis_block = (
    "\n    /** Constructor promotion: asymmetric get visibility (#5059). */\n"
    "    public int $promotionGetVisibility = 0;\n"
)
for needle in (
    "    public int $promotionSetVisibility = 0;\n",
    "    public $promotionSetVisibility = 0;\n",
    "    public int $promotionFlags = 0;\n",
    "    public $promotionFlags = 0;\n",
):
    if needle in text:
        path.write_text(text.replace(needle, needle + get_vis_block, 1))
        raise SystemExit(0)
match = re.search(r"\n    public(?: int)? \$promotion(?:SetVisibility|Flags) = 0;\n", text)
if match is not None:
    needle = match.group(0)
    path.write_text(text.replace(needle, needle + get_vis_block, 1))
    raise SystemExit(0)
sys.stderr.write("php-cfg-asymmetric-visibility: Param.php promotionSetVisibility anchor missing\n")
raise SystemExit(1)
PY
      echo "Applied php-cfg-asymmetric-visibility.patch (Param getVisibility overlay #5059)"
    fi
    if ! grep -q 'promotionSetVisibility' "$param" 2>/dev/null; then
      python3 - "$param" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if 'promotionSetVisibility' in text:
    raise SystemExit(0)
set_vis_block = (
    "\n    /** Constructor promotion: asymmetric set visibility (#3165). */\n"
    "    public int $promotionSetVisibility = 0;\n"
)
for needle in (
    "    public int $promotionGetVisibility = 0;\n",
    "    public $promotionGetVisibility = 0;\n",
    "    public bool $promotionReadonly = false;\n",
    "    public $promotionReadonly = false;\n",
    "    public int $promotionFlags = 0;\n",
    "    public $promotionFlags = 0;\n",
):
    if needle in text:
        path.write_text(text.replace(needle, needle + set_vis_block, 1))
        raise SystemExit(0)
sys.stderr.write("php-cfg-asymmetric-visibility: Param.php promotionSetVisibility anchor missing for set overlay\n")
raise SystemExit(1)
PY
      echo "Applied php-cfg-asymmetric-visibility.patch (Param setVisibility overlay #8760)"
    fi
    echo "Skip php-cfg-asymmetric-visibility.patch (already applied)"
    return 0
  fi
  if [[ $has_prop -eq 0 ]]; then
    python3 - "$prop" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if 'public int $setVisibility' in text:
    raise SystemExit(0)
needle = "    public $declaredType;\n"
insert = (
    needle
    + "\n"
    + "    /** PHP 8.4 asymmetric set visibility (0 = same as read; issue #3165). */\n"
    + "    public int $setVisibility = 0;\n"
    + "\n"
    + "    /** PHP 8.4 asymmetric get visibility (0 = same as write; issue #5059). */\n"
    + "    public int $getVisibility = 0;\n"
)
if needle not in text:
    sys.stderr.write("php-cfg-asymmetric-visibility: Property.php declaredType anchor missing\n")
    raise SystemExit(1)
path.write_text(text.replace(needle, insert, 1))
PY
    echo "Applied php-cfg-asymmetric-visibility.patch (Property overlay)"
  fi
  if [[ $has_asym_param -eq 0 ]]; then
    python3 - "$param" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if 'promotionSetVisibility' in text:
    raise SystemExit(0)
insert_block = (
    "\n"
    + "    /** Constructor promotion: asymmetric set visibility (#3165). */\n"
    + "    public int $promotionSetVisibility = 0;\n"
    + "\n"
    + "    /** Constructor promotion: asymmetric get visibility (#5059). */\n"
    + "    public int $promotionGetVisibility = 0;\n"
)
for needle in (
    "    public bool $promotionReadonly = false;\n",
    "    public $promotionReadonly = false;\n",
):
    if needle in text:
        path.write_text(text.replace(needle, needle + insert_block, 1))
        raise SystemExit(0)
for needle in (
    "    public int $promotionFlags = 0;\n",
    "    public $promotionFlags = 0;\n",
):
    if needle in text:
        path.write_text(text.replace(needle, needle + insert_block, 1))
        raise SystemExit(0)
needle = "    public $declaredType;\n\n    // A helper\n    public $function;"
if needle in text:
    insert = (
        "    public $declaredType;\n\n"
        + "    /** Constructor promotion: asymmetric set visibility (#3165). */\n"
        + "    public int $promotionSetVisibility = 0;\n"
        + "\n"
        + "    /** Constructor promotion: asymmetric get visibility (#5059). */\n"
        + "    public int $promotionGetVisibility = 0;\n\n"
        + "    // A helper\n    public $function;"
    )
    path.write_text(text.replace(needle, insert, 1))
    raise SystemExit(0)
sys.stderr.write("php-cfg-asymmetric-visibility: Param.php anchor missing\n")
raise SystemExit(1)
PY
    echo "Applied php-cfg-asymmetric-visibility.patch (Param overlay)"
  fi
  return 0
}

apply_php_cfg_asymmetric_set_visibility_parser_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/asymmetric-set-visibility-parser-methods.php"
  if [[ ! -f "$parser" || ! -f "$overlay" ]]; then
    return 0
  fi
  # Method may already exist from a prior apply that only wired promotion (#5059 path).
  # Always re-check property setVisibility wire — promotion-only trees break lazy-property.
  if grep -q 'function extractAsymmetricSetVisibilityFromAttributes' "$parser" 2>/dev/null \
    && grep -qE '\$\w+->setVisibility = \$this->extractAsymmetricSetVisibilityFromAttributes' "$parser" 2>/dev/null; then
    return 0
  fi
  python3 - "$parser" "$overlay" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()

if 'function extractAsymmetricSetVisibilityFromAttributes' not in text:
    anchor = """    protected function parseExpr_Yield(Expr\\Yield_ $expr)
    {"""
    if anchor not in text:
        sys.stderr.write("php-cfg-asymmetric-set-visibility: parseExpr_Yield anchor not found in Parser.php\n")
        raise SystemExit(1)
    insert = method_path.read_text().rstrip("\n") + "\n\n"
    text = text.replace(anchor, insert + anchor, 1)

param_needles = [
    "            $p->promotionReadonly = (bool) ($param->flags & Stmt\\Class_::MODIFIER_READONLY);\n",
    "            $p->promotionReadonly = 0 !== ($param->flags & Stmt\\Class_::MODIFIER_READONLY);\n",
]
param_insert_suffix = "            $p->promotionSetVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($p->getAttributes());\n"
for param_needle in param_needles:
    if param_needle in text and 'promotionSetVisibility = $this->extractAsymmetricSetVisibilityFromAttributes' not in text:
        text = text.replace(param_needle, param_needle + param_insert_suffix, 1)
        break

prop_needles = [
    "            $prop->propertyFlags = $node->flags;\n",
    "            $cfgProp->readonly = 0 !== ($node->flags & Node\\Stmt\\Class_::MODIFIER_READONLY);\n",
    "            $prop->readonly = 0 !== ($node->flags & Node\\Stmt\\Class_::MODIFIER_READONLY);\n",
    "            $property->readonly = 0 !== ($node->flags & Node\\Stmt\\Class_::MODIFIER_READONLY);\n",
]
for prop_needle in prop_needles:
    if prop_needle in text and 'setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes' not in text:
        if '$prop->propertyFlags' in prop_needle:
            text = text.replace(
                prop_needle,
                prop_needle + "            $prop->setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($prop->getAttributes());\n",
                1,
            )
        elif '$cfgProp->readonly' in prop_needle:
            text = text.replace(
                prop_needle,
                prop_needle + "            $cfgProp->setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($cfgProp->getAttributes());\n",
                1,
            )
        elif '$prop->readonly' in prop_needle:
            text = text.replace(
                prop_needle,
                prop_needle + "            $prop->setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($prop->getAttributes());\n",
                1,
            )
        else:
            text = text.replace(
                prop_needle,
                prop_needle + "            $property->setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($property->getAttributes());\n",
                1,
            )
        break

if 'extractAsymmetricSetVisibilityFromAttributes($p->getAttributes())' not in text \
    and 'extractAsymmetricSetVisibilityFromAttributes($prop->getAttributes())' not in text \
    and 'extractAsymmetricSetVisibilityFromAttributes($cfgProp->getAttributes())' not in text \
    and 'extractAsymmetricSetVisibilityFromAttributes($property->getAttributes())' not in text:
    sys.stderr.write("php-cfg-asymmetric-set-visibility: Parser promotion/readonly anchors missing (apply after ctor-promotion)\n")
    raise SystemExit(1)

parser_path.write_text(text)
PY
  if [[ $? -ne 0 ]]; then
    return 1
  fi
  echo "Applied php-cfg asymmetric set-visibility Parser overlay (#4690)"
}

apply_php_cfg_asymmetric_get_visibility_parser_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/asymmetric-get-visibility-parser-methods.php"
  if [[ ! -f "$parser" || ! -f "$overlay" ]]; then
    return 0
  fi
  # Method may exist while Stmt\\Property getVisibility wire was skipped (promotion-only).
  if grep -q 'function extractAsymmetricGetVisibilityFromAttributes' "$parser" 2>/dev/null \
    && grep -qE '\$\w+->getVisibility = \$this->extractAsymmetricGetVisibilityFromAttributes' "$parser" 2>/dev/null; then
    return 0
  fi
  python3 - "$parser" "$overlay" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()

if 'function extractAsymmetricGetVisibilityFromAttributes' not in text:
    anchor = """    protected function parseExpr_Yield(Expr\\Yield_ $expr)
    {"""
    if anchor not in text:
        sys.stderr.write("php-cfg-asymmetric-get-visibility: parseExpr_Yield anchor not found in Parser.php\n")
        raise SystemExit(1)
    insert = method_path.read_text().rstrip("\n") + "\n\n"
    text = text.replace(anchor, insert + anchor, 1)

param_needles = [
    "            $p->promotionSetVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($p->getAttributes());\n",
]
param_insert_suffix = "            $p->promotionGetVisibility = $this->extractAsymmetricGetVisibilityFromAttributes($p->getAttributes());\n"
for param_needle in param_needles:
    if param_needle in text and 'promotionGetVisibility = $this->extractAsymmetricGetVisibilityFromAttributes' not in text:
        text = text.replace(param_needle, param_needle + param_insert_suffix, 1)
        break

prop_needles = [
    "            $prop->setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($prop->getAttributes());\n",
    "            $cfgProp->setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($cfgProp->getAttributes());\n",
    "            $property->setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($property->getAttributes());\n",
    # Fallback when setVisibility wire was never applied — still recover get after flags.
    "            $prop->propertyFlags = $node->flags;\n",
]
for prop_needle in prop_needles:
    if prop_needle in text and 'getVisibility = $this->extractAsymmetricGetVisibilityFromAttributes' not in text:
        var = 'prop'
        if '$cfgProp->' in prop_needle:
            var = 'cfgProp'
        elif '$property->' in prop_needle:
            var = 'property'
        get_line = f"            ${var}->getVisibility = $this->extractAsymmetricGetVisibilityFromAttributes(${var}->getAttributes());\n"
        text = text.replace(prop_needle, prop_needle + get_line, 1)
        break

if 'extractAsymmetricGetVisibilityFromAttributes($p->getAttributes())' not in text \
    and 'extractAsymmetricGetVisibilityFromAttributes($prop->getAttributes())' not in text \
    and 'extractAsymmetricGetVisibilityFromAttributes($cfgProp->getAttributes())' not in text \
    and 'extractAsymmetricGetVisibilityFromAttributes($property->getAttributes())' not in text:
    sys.stderr.write("php-cfg-asymmetric-get-visibility: Parser setVisibility anchors missing (apply after set overlay)\n")
    raise SystemExit(1)

parser_path.write_text(text)
PY
  if [[ $? -ne 0 ]]; then
    return 1
  fi
  echo "Applied php-cfg asymmetric get-visibility Parser overlay (#5059)"
}

apply_php_cfg_lazy_property_overlay() {
  local property="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/lazy-property-parser-methods.php"
  if [[ ! -f "$property" || ! -f "$parser" || ! -f "$overlay" ]]; then
    return 0
  fi
  if ! grep -q 'public bool $propertyLazy' "$property" 2>/dev/null; then
    python3 - "$property" <<'PY'
import sys
from pathlib import Path
path = Path(sys.argv[1])
text = path.read_text()
lazy = (
    "\n    /** PHP 8.4 lazy property modifier recovered from phpc-lazy-property marker (#16813). */\n"
    "    public bool $propertyLazy = false;\n"
)
# Prefer propertyFlags (#3149); fall back when vendor still has legacy $readonly (#4230 path).
needle = "    public int $propertyFlags = 0;\n"
if needle in text:
    path.write_text(text.replace(needle, needle + lazy, 1))
    raise SystemExit(0)
alt = "    public $readonly = false;\n"
if alt in text:
    path.write_text(text.replace(alt, alt + lazy, 1))
    raise SystemExit(0)
sys.stderr.write(
    "php-cfg-lazy-property: Property.php propertyFlags/$readonly anchor missing\n"
)
raise SystemExit(1)
PY
    echo "Applied php-cfg-lazy-property.patch (Property overlay #16813)"
  fi
  if ! grep -q 'function extractLazyPropertyFromAttributes' "$parser" 2>/dev/null; then
    python3 - "$parser" "$overlay" <<'PY'
import sys
from pathlib import Path
parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
anchor = """    /**
     * Recover phpc-asymmetric-set:* marker from comment attributes (#3165, #4690).
     */"""
if anchor not in text:
    sys.stderr.write("php-cfg-lazy-property: asymmetric-set anchor not found in Parser.php\n")
    raise SystemExit(1)
insert = method_path.read_text().rstrip("\n") + "\n\n"
parser_path.write_text(text.replace(anchor, insert + anchor, 1))
PY
    echo "Applied php-cfg-lazy-property.patch (Parser method overlay #16813)"
  fi
  if ! grep -q 'propertyLazy = $this->extractLazyPropertyFromAttributes' "$parser" 2>/dev/null; then
    python3 - "$parser" <<'PY'
import re
import sys
from pathlib import Path
path = Path(sys.argv[1])
text = path.read_text()
# Prefer getVisibility wire (#5059); fall back to setVisibility / propertyFlags when
# asymmetric overlays only wired constructor promotion (Stmt\\Property left bare).
patterns = [
    re.compile(
        r"^([ \t]*)\$(prop|cfgProp|property)->getVisibility = \$this->extractAsymmetricGetVisibilityFromAttributes\(\$\2->getAttributes\(\)\);\n",
        re.M,
    ),
    re.compile(
        r"^([ \t]*)\$(prop|cfgProp|property)->setVisibility = \$this->extractAsymmetricSetVisibilityFromAttributes\(\$\2->getAttributes\(\)\);\n",
        re.M,
    ),
    re.compile(
        r"^([ \t]*)\$(prop|cfgProp|property)->propertyFlags = \$node->flags;\n",
        re.M,
    ),
]
m = None
for pat in patterns:
    m = pat.search(text)
    if m:
        break
if not m:
    sys.stderr.write("php-cfg-lazy-property: Parser getVisibility/setVisibility/propertyFlags anchor missing\n")
    raise SystemExit(1)
indent, var = m.group(1), m.group(2)
wire = f"{indent}${var}->propertyLazy = $this->extractLazyPropertyFromAttributes(${var}->getAttributes());\n"
path.write_text(text[: m.end()] + wire + text[m.end() :])
PY
    echo "Applied php-cfg-lazy-property.patch (Parser propertyLazy wire #16813)"
  fi
  return 0
}

apply_php_cfg_incdec_expr_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -q 'new Op\\Expr\\PostInc' "$parser" 2>/dev/null \
    && [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/PostInc.php" ]]; then
    echo "Skip php-cfg-incdec-expr.patch (already applied)"
    return 0
  fi
  for class in PostInc PreInc PostDec PreDec; do
    if [[ ! -f "$overlay/Op/Expr/${class}.php" ]]; then
      echo "Skip php-cfg-incdec-expr.patch (overlay ${class}.php missing)" >&2
      return 1
    fi
    mkdir -p "$(dirname "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/${class}.php")"
    cp "$overlay/Op/Expr/${class}.php" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/${class}.php"
  done
  if ! python3 - "$parser" "$overlay/incdec-parser-methods.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
new_methods = method_path.read_text()

if 'new Op\\Expr\\PostInc' in text:
    parser_path.write_text(text)
    raise SystemExit(0)

old = """    protected function parseExpr_PostDec(Expr\\PostDec $expr)
    {
        $var = $this->parseExprNode($expr->var);
        $read = $this->readVariable($var);
        $write = $this->writeVariable($var);
        $this->block->children[] = $op = new Op\\Expr\\BinaryOp\\Minus($read, new Operand\\Literal(1), $this->mapAttributes($expr));
        $this->block->children[] = new Op\\Expr\\Assign($write, $op->result, $this->mapAttributes($expr));

        return $read;
    }

    protected function parseExpr_PostInc(Expr\\PostInc $expr)
    {
        $var = $this->parseExprNode($expr->var);
        $read = $this->readVariable($var);
        $write = $this->writeVariable($var);
        $this->block->children[] = $op = new Op\\Expr\\BinaryOp\\Plus($read, new Operand\\Literal(1), $this->mapAttributes($expr));
        $this->block->children[] = new Op\\Expr\\Assign($write, $op->result, $this->mapAttributes($expr));

        return $read;
    }

    protected function parseExpr_PreDec(Expr\\PreDec $expr)
    {
        $var = $this->parseExprNode($expr->var);
        $read = $this->readVariable($var);
        $write = $this->writeVariable($var);
        $this->block->children[] = $op = new Op\\Expr\\BinaryOp\\Minus($read, new Operand\\Literal(1), $this->mapAttributes($expr));
        $this->block->children[] = new Op\\Expr\\Assign($write, $op->result, $this->mapAttributes($expr));

        return $op->result;
    }

    protected function parseExpr_PreInc(Expr\\PreInc $expr)
    {
        $var = $this->parseExprNode($expr->var);
        $read = $this->readVariable($var);
        $write = $this->writeVariable($var);
        $this->block->children[] = $op = new Op\\Expr\\BinaryOp\\Plus($read, new Operand\\Literal(1), $this->mapAttributes($expr));
        $this->block->children[] = new Op\\Expr\\Assign($write, $op->result, $this->mapAttributes($expr));

        return $op->result;
    }"""

if old not in text:
    sys.stderr.write("php-cfg-incdec-expr: Parser.php anchor not found\n")
    raise SystemExit(1)

parser_path.write_text(text.replace(old, new_methods.rstrip('\n'), 1))
PY
  then
    echo "ERROR: php-cfg-incdec-expr overlay failed (#6326, #6321)" >&2
    record_patch_failure "php-cfg-incdec-expr.patch" "PostInc Parser.php anchor missing"
    return 1
  fi
  echo "Applied php-cfg-incdec-expr.patch (overlay)"
}

apply_php_cfg_new_first_class_callable_overlay() {
  local fcc="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/FirstClassCallable.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if grep -q 'KIND_NEW' "$fcc" 2>/dev/null \
    && grep -q 'FirstClassCallable::KIND_NEW' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-new-first-class-callable.patch (already applied)"
    return 0
  fi
  if ! grep -q 'isFirstClassCallable' "$parser" 2>/dev/null; then
    echo "ERROR: php-cfg-new-first-class-callable requires php-cfg-first-class-callable (#9767)" >&2
    record_patch_failure "php-cfg-new-first-class-callable.patch" "isFirstClassCallable missing"
    return 1
  fi
  if ! python3 - "$fcc" "$parser" <<'PY'
import sys
from pathlib import Path

fcc_path = Path(sys.argv[1])
parser_path = Path(sys.argv[2])
fcc_text = fcc_path.read_text()
parser_text = parser_path.read_text()

if 'KIND_NEW' in fcc_text and 'FirstClassCallable::KIND_NEW' in parser_text:
    raise SystemExit(0)

fcc_old_comment = "/** PHP 8.1+ first-class callable: `foo(...)`, `Class::m(...)`, `$obj->m(...)` (#1230). */"
fcc_new_comment = "/** PHP 8.1+ first-class callable: `foo(...)`, `Class::m(...)`, `$obj->m(...)`, `new C(...)` (#1230, #9767). */"
if fcc_old_comment in fcc_text:
    fcc_text = fcc_text.replace(fcc_old_comment, fcc_new_comment, 1)
elif fcc_new_comment not in fcc_text:
    sys.stderr.write("php-cfg-new-first-class-callable: FirstClassCallable.php comment anchor not found\n")
    raise SystemExit(1)

kind_method = "    public const KIND_METHOD = 3;"
kind_new = """    public const KIND_METHOD = 3;
    public const KIND_NEW = 4;"""
if kind_new not in fcc_text:
    if kind_method not in fcc_text:
        sys.stderr.write("php-cfg-new-first-class-callable: KIND_METHOD anchor not found\n")
        raise SystemExit(1)
    fcc_text = fcc_text.replace(kind_method, kind_new, 1)

fcc_block = """        if ($this->isFirstClassCallable($expr->args)) {
            if ($expr->class instanceof Stmt\\Class_) {
                $this->parseStmt_Class($expr->class);
                $class = $this->readVariable($this->parseExprNode($expr->class->namespacedName));
            } else {
                $class = $this->readVariable($this->parseExprNode($expr->class));
            }

            return new Op\\Expr\\FirstClassCallable(
                Op\\Expr\\FirstClassCallable::KIND_NEW,
                $class,
                $class,
                null,
                $this->mapAttributes($expr)
            );
        }

"""

if 'FirstClassCallable::KIND_NEW' in parser_text:
    fcc_path.write_text(fcc_text)
    raise SystemExit(0)

anchors = [
    """    protected function parseExpr_New(Expr\\New_ $expr)
    {
""",
]
for anchor in anchors:
    if anchor in parser_text and 'FirstClassCallable::KIND_NEW' not in parser_text:
        parser_text = parser_text.replace(anchor, anchor + fcc_block, 1)
        fcc_path.write_text(fcc_text)
        parser_path.write_text(parser_text)
        raise SystemExit(0)

sys.stderr.write("php-cfg-new-first-class-callable: parseExpr_New anchor not found\n")
raise SystemExit(1)
PY
  then
    echo "ERROR: php-cfg-new-first-class-callable overlay failed (#9931, #9767)" >&2
    record_patch_failure "php-cfg-new-first-class-callable.patch" "parseExpr_New anchor missing"
    return 1
  fi
  echo "Applied php-cfg-new-first-class-callable.patch (overlay)"
}

apply_php_cfg_yield_keyed_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -A2 'if ($expr->value)' "$parser" 2>/dev/null | grep -q '\$value = \$this->readVariable'; then
    echo "Skip php-cfg-yield-keyed.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay/yield-parser-method.php" ]]; then
    echo "Skip php-cfg-yield-keyed.patch (overlay files missing)" >&2
    return 1
  fi
  python3 - "$parser" "$overlay/yield-parser-method.php" <<'PY'
import re
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
replacement = method_path.read_text().rstrip("\n") + "\n"
pattern = r"    protected function parseExpr_Yield\(Expr\\Yield_ \$expr\)\s*\{.*?\n    \}\n\n"
match = re.search(pattern, text, re.S)
if not match:
    sys.stderr.write("php-cfg-yield-keyed: parseExpr_Yield method not found in Parser.php\n")
    raise SystemExit(1)
parser_path.write_text(text[: match.start()] + replacement + text[match.end() :])
PY
  echo "Applied php-cfg-yield-keyed.patch (overlay)"
}

# Repair Enum_ Parser ctor when Stmt\\Enum_ gained $implements but Parser still passes Block (#3083).
apply_php_cfg_enum_implements_parser_fix() {
  local parser="$1"
  python3 - "$parser" <<'PY'
import re
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
enum_block = re.search(
    r'protected function parseStmt_Enum\(Stmt\\Enum_ \$node\)\s*\{.*?\n    \}\n',
    text,
    re.S,
)
if enum_block and 'parseExprList($node->implements)' in enum_block.group(0):
    raise SystemExit(0)
pattern = re.compile(
    r"(        \$this->block->children\[\] = new Op\\Stmt\\Enum_\(\n"
    r"            \$name,\n"
    r"            \$backedType,\n)"
    r"(            \$stmtsBlock,)",
    re.MULTILINE,
)
replacement = r"\1            $this->parseExprList($node->implements),\n\2"
new_text, count = pattern.subn(replacement, text, count=1)
if count != 1:
    sys.stderr.write("php-cfg-enum-implements: Enum_ ctor call not found in Parser.php\n")
    raise SystemExit(1)
parser_path.write_text(new_text)
PY
}

apply_php_cfg_enum_class_method_parser_fix() {
  local parser="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php}"
  if grep -q 'elseif ($stmt instanceof Stmt\\ClassMethod)' "$parser" 2>/dev/null \
    && grep -A20 'function parseStmt_Enum' "$parser" | grep -q 'Stmt\\ClassMethod'; then
    return 0
  fi
  if ! grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-enum-class-method.patch (parseStmt_Enum missing)" >&2
    return 1
  fi
  apply_patch "$PATCH_DIR/php-cfg-enum-class-method.patch"
}

apply_php_cfg_enum_trait_use_parser_fix() {
  local parser="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php}"
  if grep -A35 'function parseStmt_Enum' "$parser" 2>/dev/null | grep -q 'Stmt\\TraitUse'; then
    return 0
  fi
  if ! grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-enum-trait-use.patch (parseStmt_Enum missing)" >&2
    return 1
  fi
  python3 - "$parser" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
trait_branch = """            } elseif ($stmt instanceof Stmt\\TraitUse) {
                $this->parseStmt_TraitUse($stmt);
"""
if "Stmt\\TraitUse" in text.split("function parseStmt_Enum", 1)[-1].split("function parseEnumCase", 1)[0]:
    raise SystemExit(0)

with_class_const = """            } elseif ($stmt instanceof Stmt\\ClassMethod) {
                $this->parseStmt_ClassMethod($stmt);
            } elseif ($stmt instanceof Stmt\\ClassConst) {
                $this->parseStmt_ClassConst($stmt);
            }"""
with_class_const_trait = """            } elseif ($stmt instanceof Stmt\\ClassMethod) {
                $this->parseStmt_ClassMethod($stmt);
            } elseif ($stmt instanceof Stmt\\TraitUse) {
                $this->parseStmt_TraitUse($stmt);
            } elseif ($stmt instanceof Stmt\\ClassConst) {
                $this->parseStmt_ClassConst($stmt);
            }"""
without_class_const = """            } elseif ($stmt instanceof Stmt\\ClassMethod) {
                $this->parseStmt_ClassMethod($stmt);
            }
        }
        $this->block = $savedBlock;"""
without_class_const_trait = """            } elseif ($stmt instanceof Stmt\\ClassMethod) {
                $this->parseStmt_ClassMethod($stmt);
            } elseif ($stmt instanceof Stmt\\TraitUse) {
                $this->parseStmt_TraitUse($stmt);
            }
        }
        $this->block = $savedBlock;"""

if with_class_const in text:
    text = text.replace(with_class_const, with_class_const_trait, 1)
elif without_class_const in text:
    text = text.replace(without_class_const, without_class_const_trait, 1)
else:
    sys.stderr.write("php-cfg-enum-trait-use: parseStmt_Enum loop anchor not found\n")
    raise SystemExit(1)
parser_path.write_text(text)
PY
  echo "Applied php-cfg-enum-trait-use.patch (overlay)"
}

apply_php_cfg_enum_class_const_overlay() {
  local const_file="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Const_.php"
  local parser="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php}"
  local already_applied=0
  if patch_already_applied "$PATCH_DIR/php-cfg-enum-class-const.patch"; then
    already_applied=1
  fi
  if ! grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-enum-class-const.patch (parseStmt_Enum missing)" >&2
    return 1
  fi
  if [[ "$already_applied" -eq 1 ]] \
    && grep -q 'enumCaseHasExplicitValue' "$const_file" 2>/dev/null \
    && grep -q 'enumCaseHasExplicitValue' "$parser" 2>/dev/null; then
    return 0
  fi
  python3 - "$const_file" "$parser" <<'PY'
import sys
from pathlib import Path

const_path = Path(sys.argv[1])
parser_path = Path(sys.argv[2])
const_text = const_path.read_text()
if "isEnumCase" not in const_text:
    insert = (
        "    /** True for `case Name = value` in enums; false for `const` in enum/class bodies (#5054). */\n"
        "    public bool $isEnumCase = false;\n\n"
        "    /** True when enum case declares `= value`; false for unit enum implicit case (#5397). */\n"
        "    public bool $enumCaseHasExplicitValue = false;\n\n"
    )
    for old in (
        "    public int $flags = 0;\n\n    public function __construct",
        "    public bool $enumCaseHasExplicitValue = false;\n\n    public function __construct",
        "    public bool $isEnumCase = false;\n\n    public function __construct",
        "    public ?Type $declaredType = null;\n\n    public function __construct",
        "    public $valueBlock;\n\n    public function __construct",
    ):
        if old in const_text:
            const_path.write_text(
                const_text.replace(old, old.replace("\n\n    public function __construct", "\n\n" + insert + "    public function __construct", 1), 1)
            )
            break
    else:
        sys.stderr.write("php-cfg-enum-class-const: Const_.php anchor not found\n")
        raise SystemExit(1)

text = parser_path.read_text()
enum_loop_old = """            } elseif ($stmt instanceof Stmt\\ClassMethod) {
                $this->parseStmt_ClassMethod($stmt);
            }
        }
        $this->block = $savedBlock;"""
enum_loop_new = """            } elseif ($stmt instanceof Stmt\\ClassMethod) {
                $this->parseStmt_ClassMethod($stmt);
            } elseif ($stmt instanceof Stmt\\ClassConst) {
                $this->parseStmt_ClassConst($stmt);
            }
        }
        $this->block = $savedBlock;"""
if "Stmt\\ClassConst" not in text.split("function parseStmt_Enum", 1)[-1].split("function parseEnumCase", 1)[0]:
    if enum_loop_old not in text:
        sys.stderr.write("php-cfg-enum-class-const: parseStmt_Enum loop anchor not found\n")
        raise SystemExit(1)
    text = text.replace(enum_loop_old, enum_loop_new, 1)

case_old = """        $this->block->children[] = new Op\\Terminal\\Const_(
            $this->parseExprNode($node->name),
            $value,
            $valueBlock,
            $this->mapAttributes($node)
        );"""
case_new = """        $constOp = new Op\\Terminal\\Const_(
            $this->parseExprNode($node->name),
            $value,
            $valueBlock,
            $this->mapAttributes($node)
        );
        $constOp->isEnumCase = true;
        $constOp->enumCaseHasExplicitValue = null !== $node->expr;
        $this->block->children[] = $constOp;"""
if case_old in text:
    text = text.replace(case_old, case_new, 1)
elif "enumCaseHasExplicitValue" not in text.split("function parseEnumCase", 1)[-1].split("function parseStmt_Echo", 1)[0]:
    case_is_only = """        $constOp->isEnumCase = true;
        $this->block->children[] = $constOp;"""
    case_is_explicit = """        $constOp->isEnumCase = true;
        $constOp->enumCaseHasExplicitValue = null !== $node->expr;
        $this->block->children[] = $constOp;"""
    if case_is_only in text:
        text = text.replace(case_is_only, case_is_explicit, 1)

const_fresh = const_path.read_text()
if "enumCaseHasExplicitValue" not in const_fresh and "isEnumCase" in const_fresh:
    const_text = const_fresh.replace(
        "    public bool $isEnumCase = false;\n\n",
        "    public bool $isEnumCase = false;\n\n"
        "    /** True when enum case declares `= value`; false for unit enum implicit case (#5397). */\n"
        "    public bool $enumCaseHasExplicitValue = false;\n\n",
        1,
    )
    const_path.write_text(const_text)

parser_path.write_text(text)
PY
  if grep -q 'enumCaseHasExplicitValue' "$const_file" 2>/dev/null \
    && grep -q 'enumCaseHasExplicitValue' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-enum-class-const.patch (already applied)"
  else
    echo "Applied php-cfg-enum-class-const.patch (overlay)"
  fi
}

apply_php_cfg_enum_class_const_parser_fix() {
  apply_php_cfg_enum_class_const_overlay
}

apply_php_cfg_enum_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Enum_.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  mkdir -p "$(dirname "$op")"
  cp "$overlay/Op/Stmt/Enum_.php" "$op"
  if grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null \
    && php_cfg_enum_implements_parser_applied "$parser" \
    && grep -A25 'function parseStmt_Enum' "$parser" | grep -q 'Stmt\\ClassMethod'; then
    echo "Skip php-cfg-enum.patch (already applied)"
    php_cfg_sync_enum_flags_parser "$parser" "$op" || true
    apply_php_cfg_enum_trait_use_parser_fix "$parser"
    apply_php_cfg_enum_class_const_parser_fix "$parser"
    return 0
  fi
  if ! grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    python3 - "$parser" "$overlay/enum-parser-methods.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
anchor = "    protected function parseStmt_Echo(Stmt\\Echo_ $node)"
insert = method_path.read_text().rstrip("\n") + "\n\n"
if anchor not in text:
    sys.stderr.write("php-cfg-enum: parseStmt_Echo anchor not found in Parser.php\n")
    raise SystemExit(1)
parser_path.write_text(text.replace(anchor, insert + anchor, 1))
PY
    echo "Applied php-cfg-enum.patch (overlay)"
  else
    echo "Repair php-cfg-enum.patch (partial Parser.php)"
  fi
  apply_php_cfg_enum_implements_parser_fix "$parser"
  apply_php_cfg_enum_class_method_parser_fix "$parser"
  apply_php_cfg_enum_trait_use_parser_fix "$parser"
  apply_php_cfg_enum_class_const_parser_fix "$parser"
  php_cfg_sync_enum_flags_parser "$parser" "$op" || true
}

apply_php_cfg_enum_implements_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Enum_.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if ! grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-enum-implements.patch (parseStmt_Enum missing; apply php-cfg-enum.patch first)" >&2
    return 1
  fi
  install_overlay_file "$op" "$overlay/Op/Stmt/Enum_.php" "php-cfg-enum-implements Enum_.php"
  if php_cfg_enum_implements_parser_applied "$parser" \
    && grep -q 'public $implements' "$op" 2>/dev/null; then
    echo "Skip php-cfg-enum-implements.patch (already applied)"
    return 0
  fi
  apply_php_cfg_enum_implements_parser_fix "$parser"
  echo "Applied php-cfg-enum-implements.patch (overlay)"
  php_cfg_sync_enum_flags_parser "$parser" "$op" || true
}

php_cfg_enum_flags_parser_applied() {
  local parser="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php}"
  [[ -f "$parser" ]] || return 1
  grep -A12 'new Op\\Stmt\\Enum_' "$parser" 2>/dev/null | grep -q '\$flags,'
}

php_cfg_enum_op_expects_flags() {
  local op="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Enum_.php}"
  [[ -f "$op" ]] || return 1
  grep -q 'int \$flags' "$op" 2>/dev/null
}

php_cfg_apply_enum_flags_parser_fix() {
  local parser="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php}"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if ! grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    return 1
  fi
  if php_cfg_enum_flags_parser_applied "$parser"; then
    return 0
  fi
  python3 - "$parser" "$overlay/enum-abstract-parser-method.php" <<'PY'
import re
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
replacement = method_path.read_text().rstrip("\n") + "\n"
pattern = r"    protected function parseStmt_Enum\(Stmt\\Enum_ \$node\)\s*\{.*?\n    \}\n"
match = re.search(pattern, text, re.S)
if not match:
    sys.stderr.write("php-cfg-enum-abstract: parseStmt_Enum method not found in Parser.php\n")
    raise SystemExit(1)
parser_path.write_text(text[: match.start()] + replacement + text[match.end() :])
PY
}

# Keep Enum_.php flags ctor and parseStmt_Enum in sync (#3114).
php_cfg_sync_enum_flags_parser() {
  local parser="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php}"
  local op="${2:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Enum_.php}"
  if ! php_cfg_enum_op_expects_flags "$op"; then
    return 0
  fi
  if php_cfg_enum_flags_parser_applied "$parser"; then
    return 0
  fi
  php_cfg_apply_enum_flags_parser_fix "$parser"
  apply_php_cfg_enum_trait_use_parser_fix "$parser" || true
  echo "Repair php-cfg-enum-abstract.patch (Enum_ flags ctor vs Parser.php)"
}

# Run enum overlays before patches that may fail and abort the php-cfg block (#3114).
apply_php_cfg_enum_early_chain() {
  [[ -d "$ROOT/vendor/ircmaxell/php-cfg" ]] || return 0
  apply_php_cfg_enum_overlay || true
  apply_php_cfg_enum_implements_overlay || true
  apply_php_cfg_enum_class_method_parser_fix || true
  apply_php_cfg_enum_trait_use_parser_fix || true
  apply_php_cfg_enum_class_const_parser_fix || true
  apply_php_cfg_enum_abstract_overlay || true
  php_cfg_sync_enum_flags_parser || true
}

apply_php_cfg_enum_abstract_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Enum_.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if ! grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-enum-abstract.patch (parseStmt_Enum missing)" >&2
    return 1
  fi
  cp "$overlay/Op/Stmt/Enum_.php" "$op"
  if php_cfg_enum_flags_parser_applied "$parser"; then
    echo "Skip php-cfg-enum-abstract.patch (already applied)"
    return 0
  fi
  php_cfg_apply_enum_flags_parser_fix "$parser"
  echo "Applied php-cfg-enum-abstract.patch (overlay)"
}

apply_php_cfg_intersection_type_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local printer="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Printer.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Type/Intersection.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/Op/Type/Intersection.php"
  if [[ -f "$op" ]] && grep -q 'IntersectionType' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-intersection-type.patch (already applied)"
    return 0
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay" "$op"
  python3 - "$parser" "$printer" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
printer_path = Path(sys.argv[2])

parser = parser_path.read_text()
if 'Node\\IntersectionType' not in parser:
    anchor = "        throw new \\LogicException('Unknown type node: '.$node->getType());"
    insert = """        if ($node instanceof Node\\IntersectionType) {
            $types = [];
            foreach ($node->types as $sub) {
                $types[] = $this->parseTypeNode($sub);
            }

            return new Op\\Type\\Intersection($types, $this->mapAttributes($node));
        }

"""
    if anchor not in parser:
        sys.stderr.write("php-cfg-intersection-type: throw anchor not found in Parser.php\\n")
        raise SystemExit(1)
    parser = parser.replace(anchor, insert + anchor, 1)
    parser_path.write_text(parser)

printer = printer_path.read_text()
if 'Op\\\\Type\\\\Intersection' not in printer:
    anchor = "        if ($type instanceof Op\\Type\\Literal) {"
    insert = """        if ($type instanceof Op\\Type\\Intersection) {
            return implode('&', array_map(
                fn (Op\\Type $t) => $this->renderType($t),
                $type->types
            ));
        }
"""
    if anchor not in printer:
        sys.stderr.write("php-cfg-intersection-type: Literal anchor not found in Printer.php\\n")
        raise SystemExit(1)
    printer = printer.replace(anchor, insert + anchor, 1)
    printer_path.write_text(printer)
PY
  echo "Applied php-cfg-intersection-type.patch (overlay)"
}

apply_php_cfg_instanceof_union_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local instanceof_op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/InstanceOf_.php"
  local methods="$PATCH_DIR/overlays/php-cfg/instanceof-union-parser-methods.php"
  if grep -q 'parseInstanceofClassUnion' "$parser" 2>/dev/null \
    && grep -q 'classUnion' "$instanceof_op" 2>/dev/null; then
    echo "Skip php-cfg-instanceof-union.patch (already applied)"
    return 0
  fi
  python3 - "$parser" "$instanceof_op" "$methods" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
instanceof_path = Path(sys.argv[2])
methods_path = Path(sys.argv[3])
methods = methods_path.read_text()

parser = parser_path.read_text()
if 'parseInstanceofClassUnion' not in parser:
    anchor = "    protected function parseExpr_Instanceof(Expr\\Instanceof_ $expr)"
    if anchor not in parser:
        sys.stderr.write("php-cfg-instanceof-union: parseExpr_Instanceof anchor not found\\n")
        raise SystemExit(1)
    parser = parser.replace(anchor, methods + anchor, 1)
    old_body = """        $var = $this->readVariable($this->parseExprNode($expr->expr));
        $class = $this->readVariable($this->parseExprNode($expr->class));"""
    new_body = """        $var = $this->readVariable($this->parseExprNode($expr->expr));
        $union = $this->parseInstanceofClassUnion($expr->class);
        if (null !== $union) {
            $class = $this->readVariable(new Literal(''));
            $op = new Op\\Expr\\InstanceOf_($var, $class, $this->mapAttributes($expr));
            $op->classUnion = $union;

            return $op;
        }
        $class = $this->readVariable($this->parseExprNode($expr->class));"""
    if old_body not in parser:
        sys.stderr.write("php-cfg-instanceof-union: instanceof body anchor not found\\n")
        raise SystemExit(1)
    parser = parser.replace(old_body, new_body, 1)
    parser_path.write_text(parser)

instanceof_src = instanceof_path.read_text()
if 'classUnion' not in instanceof_src:
    anchor = "use PhpCfg\\Operand;"
    insert = "use PhpCfg\\Operand;\nuse PHPCfg\\Op\\Type;"
    if anchor not in instanceof_src:
        sys.stderr.write("php-cfg-instanceof-union: Operand import anchor not found\\n")
        raise SystemExit(1)
    instanceof_src = instanceof_src.replace(anchor, insert, 1)
    prop_anchor = "    public $class;\n"
    prop_insert = """    public $class;

    /** @var null|Type\\Union_ union RHS for $obj instanceof (A|B) (#3461) */
    public $classUnion = null;
"""
    if prop_anchor not in instanceof_src:
        sys.stderr.write("php-cfg-instanceof-union: class property anchor not found\\n")
        raise SystemExit(1)
    instanceof_src = instanceof_src.replace(prop_anchor, prop_insert, 1)
    instanceof_path.write_text(instanceof_src)
PY
  echo "Applied php-cfg-instanceof-union.patch (overlay)"
}

apply_php_cfg_union_type_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local printer="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Printer.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Type/Union_.php"
  if [[ -f "$op" ]] && awk '/protected function parseTypeNode\(/,/throw new \\LogicException\('"'"'Unknown type node:/' "$parser" 2>/dev/null | grep -q 'Node\\UnionType'; then
    echo "Skip php-cfg-union-type.patch (already applied)"
    return 0
  fi
  mkdir -p "$(dirname "$op")"
  cat >"$op" <<'PHP'
<?php

declare(strict_types=1);

namespace PHPCfg\Op\Type;

use PHPCfg\Op\Type;

class Union_ extends Type
{
    /** @var Type[] */
    public $types;

    public function __construct(array $types, array $attributes = [])
    {
        $this->types = $types;
    }
}
PHP
  python3 - "$parser" "$printer" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
printer_path = Path(sys.argv[2])

parser = parser_path.read_text()
import re

parse_type = re.search(
    r'protected function parseTypeNode\(.*?throw new \\LogicException\(\'Unknown type node:',
    parser,
    re.S,
)
parse_type_body = parse_type.group(0) if parse_type else ''
if 'Node\\UnionType' not in parse_type_body:
    anchor = "        throw new \\LogicException('Unknown type node: '.$node->getType());"
    insert = """        if ($node instanceof Node\\UnionType) {
            $types = [];
            foreach ($node->types as $sub) {
                $types[] = $this->parseTypeNode($sub);
            }

            return new Op\\Type\\Union_($types, $this->mapAttributes($node));
        }

"""
    if anchor not in parser:
        sys.stderr.write("php-cfg-union-type: throw anchor not found in Parser.php\\n")
        raise SystemExit(1)
    parser = parser.replace(anchor, insert + anchor, 1)
    parser_path.write_text(parser)

printer = printer_path.read_text()
if 'Op\\\\Type\\\\Union_' not in printer:
    anchor = "        if ($type instanceof Op\\Type\\Literal) {"
    insert = """        if ($type instanceof Op\\Type\\Union_) {
            return implode('|', array_map(
                fn (Op\\Type $t) => $this->renderType($t),
                $type->types
            ));
        }
"""
    if anchor not in printer:
        sys.stderr.write("php-cfg-union-type: Literal anchor not found in Printer.php\\n")
        raise SystemExit(1)
    printer = printer.replace(anchor, insert + anchor, 1)
    printer_path.write_text(printer)
PY
  echo "Applied php-cfg-union-type.patch (overlay)"
}

apply_php_cfg_attribute_groups_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if grep -q "attrGroups'\] = \$expr->attrGroups" "$parser" 2>/dev/null; then
    echo "Skip php-cfg-attribute-groups.patch (already applied)"
    return 0
  fi
  python3 - "$parser" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = """    private function mapAttributes(Node $expr)
    {
        return array_merge(
            [
                'filename' => $this->fileName,
                'doccomment' => $expr->getDocComment(),
            ],
            $expr->getAttributes()
        );
    }"""
new = """    private function mapAttributes(Node $expr)
    {
        $attrs = array_merge(
            [
                'filename' => $this->fileName,
                'doccomment' => $expr->getDocComment(),
            ],
            $expr->getAttributes()
        );
        if (property_exists($expr, 'attrGroups') && [] !== $expr->attrGroups) {
            $attrs['attrGroups'] = $expr->attrGroups;
        }
        return $attrs;
    }"""
if old not in text:
    sys.stderr.write("php-cfg-attribute-groups: mapAttributes anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-cfg-attribute-groups.patch (overlay)"
}

