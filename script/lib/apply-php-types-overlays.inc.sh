# Sourced from script/apply-patches.sh — php-types overlays (#36403 size ratchet).
# Requires ROOT, PATCH_DIR, and helpers: install_overlay_file, patch_already_applied.
# shellcheck shell=bash

apply_php_types_intersection_type_reconstructor_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    echo "Skip php-types-intersection-type.patch (target missing): ${target}"
    return 0
  fi
  if grep -q 'instanceof Op\\Type\\Intersection' "$target" 2>/dev/null && php -l "$target" >/dev/null 2>&1; then
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
intersection_branch = """        } elseif ($type instanceof Op\\Type\\Intersection) {
            $subs = [];
            foreach ($type->types as $sub) {
                $subs[] = $this->resolveOpType($sub);
            }

            return new Type(Type::TYPE_INTERSECTION, $subs);
        }
"""
throw_anchor = """        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));"""
union_close = (
    "            return (new Type(Type::TYPE_UNION, $subs))->simplify();\n"
    "        }\n\n"
    + throw_anchor
)
if "instanceof Op\\Type\\Intersection" in text:
    raise SystemExit(0)
if union_close in text:
    text = text.replace(union_close, union_close.replace("\n\n" + throw_anchor, "\n" + intersection_branch + "\n" + throw_anchor, 1), 1)
elif throw_anchor in text:
    text = text.replace(throw_anchor, intersection_branch + "\n" + throw_anchor, 1)
else:
    sys.stderr.write("php-types-intersection-type: TypeReconstructor anchor not found\n")
    raise SystemExit(1)
path.write_text(text)
PY
  then
    echo "ERROR: php-types-intersection-type overlay failed for ${target} (#6820)" >&2
    return 1
  fi
  echo "Applied php-types-intersection-type.patch (TypeReconstructor overlay): ${target}"
}

apply_php_types_intersection_type_type_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    echo "Skip php-types-intersection-type.patch (target missing): ${target}"
    return 0
  fi
  if grep -q 'instanceof CfgType\\Union_' "$target" 2>/dev/null \
    && grep -q 'instanceof CfgType\\Intersection' "$target" 2>/dev/null; then
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()

anchor = "        throw new \\LogicException('Unsupported declaration type: '.get_class($decl));"
if anchor not in text:
    sys.stderr.write("php-types-intersection-type: throw anchor not found\n")
    raise SystemExit(1)

union_block = """        if ($decl instanceof CfgType\\Union_) {
            $subs = [];
            foreach ($decl->types as $sub) {
                $subs[] = self::fromTypeDecl($sub);
            }

            return new self(self::TYPE_UNION, $subs);
        }
"""
intersection_block = """        if ($decl instanceof CfgType\\Intersection) {
            $subs = [];
            foreach ($decl->types as $sub) {
                $subs[] = self::fromTypeDecl($sub);
            }

            return new self(self::TYPE_INTERSECTION, $subs);
        }

"""
insert = ""
if "instanceof CfgType\\Union_" not in text:
    insert += union_block
if "instanceof CfgType\\Intersection" not in text:
    insert += intersection_block
if not insert:
    raise SystemExit(0)
path.write_text(text.replace(anchor, insert + anchor, 1))
PY
  then
    echo "ERROR: php-types-intersection-type Type.php overlay failed for ${target} (#6820)" >&2
    return 1
  fi
  echo "Applied php-types-intersection-type.patch (Type.php overlay): ${target}"
}

apply_php_types_intersection_type_overlay() {
  local rc=0
  local vendor_type="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  local prelinked_type="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/Type.php"
  local vendor_recon="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local prelinked_recon="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local applied=0

  if [[ -f "$vendor_type" ]] && ! grep -q 'instanceof CfgType\\Intersection' "$vendor_type" 2>/dev/null; then
    apply_php_types_intersection_type_type_overlay_to_target "$vendor_type" || rc=1
    applied=1
  fi
  if [[ -f "$prelinked_type" ]] && ! grep -q 'instanceof CfgType\\Intersection' "$prelinked_type" 2>/dev/null; then
    apply_php_types_intersection_type_type_overlay_to_target "$prelinked_type" || rc=1
    applied=1
  fi
  if ! apply_php_types_intersection_type_reconstructor_overlay_to_target "$vendor_recon"; then
    rc=1
  elif [[ -f "$vendor_recon" ]] \
    && ! grep -q 'instanceof Op\\Type\\Intersection' "$vendor_recon" 2>/dev/null; then
    applied=1
  fi
  if ! apply_php_types_intersection_type_reconstructor_overlay_to_target "$prelinked_recon"; then
    rc=1
  elif [[ -f "$prelinked_recon" ]] \
    && ! grep -q 'instanceof Op\\Type\\Intersection' "$prelinked_recon" 2>/dev/null; then
    applied=1
  fi
  if [[ "$applied" -eq 0 ]]; then
    echo "Skip php-types-intersection-type.patch (already applied)"
  else
    echo "Applied php-types-intersection-type.patch (overlay)"
  fi
  return "$rc"
}

repair_php_types_union_type_reconstructor_at() {
  local target="$1"
  local ssot="$2"
  [[ -f "$target" ]] || return 0
  if php -l "$target" >/dev/null 2>&1; then
    return 0
  fi
  echo "apply-patches: repairing malformed php-types-union-type in ${target} (#4229)" >&2
  if [[ ! -f "$ssot" ]]; then
    echo "apply-patches: missing SSOT ${ssot} for TypeReconstructor repair" >&2
    return 1
  fi
  python3 - "$target" "$ssot" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
ssot = Path(sys.argv[2])
text = path.read_text()
ssot_text = ssot.read_text()
start = text.find("    private function resolveOpType(Op\\Type $type): Type")
end = text.find("    private function resolveMethodCall", start)
ssot_start = ssot_text.find("    private function resolveOpType(Op\\Type $type): Type")
ssot_end = ssot_text.find("    private function resolveMethodCall", ssot_start)
if -1 in (start, end, ssot_start, ssot_end) or end <= start or ssot_end <= ssot_start:
    sys.stderr.write("php-types-union-type-repair: resolveOpType anchors not found\n")
    raise SystemExit(1)
path.write_text(text[:start] + ssot_text[ssot_start:ssot_end] + text[end:])
PY
}

repair_php_types_union_type_reconstructor_if_needed() {
  local vendor_recon="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local prelinked_recon="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  repair_php_types_union_type_reconstructor_at "$vendor_recon" "$prelinked_recon" || return 1
  if [[ -f "$prelinked_recon" ]] && ! php -l "$prelinked_recon" >/dev/null 2>&1; then
    repair_php_types_union_type_reconstructor_at "$prelinked_recon" "$vendor_recon" || return 1
  fi
}

apply_php_types_union_type_reconstructor_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    echo "Skip php-types-union-type.patch (target missing): ${target}"
    return 0
  fi
  if grep -q 'instanceof Op\\Type\\Union_' "$target" 2>/dev/null \
    && grep -q 'instanceof Op\\Type\\Intersection' "$target" 2>/dev/null \
    && php -l "$target" >/dev/null 2>&1; then
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
union_body = """            $subs = [];
            foreach ($type->types as $sub) {
                $subs[] = $this->resolveOpType($sub);
            }

            return (new Type(Type::TYPE_UNION, $subs))->simplify();
"""
union_before_intersection = (
    "        } elseif ($type instanceof Op\\Type\\Union_) {\n"
    + union_body
)
if "instanceof Op\\Type\\Union_" in text:
    raise SystemExit(0)

intersection_anchor = """        } elseif ($type instanceof Op\\Type\\Intersection) {"""
never_throw_anchor = """        } elseif ($type instanceof Op\\Type\\Never_) {
            return Type::never();
        }

        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));"""
literal_throw_anchor = """        } elseif ($type instanceof Op\\Type\\Literal) {
            return Type::fromDecl($type->name);
        }

        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));"""
intersection_branch = """        } elseif ($type instanceof Op\\Type\\Intersection) {
            $subs = [];
            foreach ($type->types as $sub) {
                $subs[] = $this->resolveOpType($sub);
            }

            return new Type(Type::TYPE_INTERSECTION, $subs);
        }
"""
never_union_tail = (
    """        } elseif ($type instanceof Op\\Type\\Never_) {
            return Type::never();
        } elseif ($type instanceof Op\\Type\\Union_) {
"""
    + union_body
    + intersection_branch
    + """

        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));"""
)
literal_union_tail = (
    """        } elseif ($type instanceof Op\\Type\\Literal) {
            return Type::fromDecl($type->name);
        } elseif ($type instanceof Op\\Type\\Never_) {
            return Type::never();
        } elseif ($type instanceof Op\\Type\\Union_) {
"""
    + union_body
    + intersection_branch
    + """

        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));"""
)

if intersection_anchor in text:
    text = text.replace(intersection_anchor, union_before_intersection + intersection_anchor, 1)
elif never_throw_anchor in text:
    text = text.replace(never_throw_anchor, never_union_tail, 1)
elif literal_throw_anchor in text:
    text = text.replace(literal_throw_anchor, literal_union_tail, 1)
else:
    sys.stderr.write(
        "php-types-union-type: TypeReconstructor anchor not found "
        "(expected Intersection handler, Never_/throw tail, or Literal/throw tail)\n"
    )
    raise SystemExit(1)
path.write_text(text)
PY
  then
    echo "ERROR: php-types-union-type overlay failed for ${target} (#6820)" >&2
    return 1
  fi
  echo "Applied php-types-union-type.patch (TypeReconstructor overlay): ${target}"
}

apply_php_types_union_type_overlay() {
  local rc=0
  local vendor_type="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  local prelinked_type="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/Type.php"
  local vendor_recon="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local prelinked_recon="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local applied=0

  repair_php_types_union_type_reconstructor_if_needed || rc=1

  if [[ -f "$vendor_type" ]] && ! grep -q 'instanceof CfgType\\Union_' "$vendor_type" 2>/dev/null; then
    apply_php_types_intersection_type_type_overlay_to_target "$vendor_type" || rc=1
    applied=1
  fi
  if [[ -f "$prelinked_type" ]] && ! grep -q 'instanceof CfgType\\Union_' "$prelinked_type" 2>/dev/null; then
    apply_php_types_intersection_type_type_overlay_to_target "$prelinked_type" || rc=1
    applied=1
  fi
  if [[ -f "$vendor_recon" ]] \
    && { ! grep -q 'instanceof Op\\Type\\Union_' "$vendor_recon" 2>/dev/null \
      || ! php -l "$vendor_recon" >/dev/null 2>&1; }; then
    apply_php_types_union_type_reconstructor_overlay_to_target "$vendor_recon" || rc=1
    applied=1
  fi
  if [[ -f "$prelinked_recon" ]] \
    && { ! grep -q 'instanceof Op\\Type\\Union_' "$prelinked_recon" 2>/dev/null \
      || ! php -l "$prelinked_recon" >/dev/null 2>&1; }; then
    apply_php_types_union_type_reconstructor_overlay_to_target "$prelinked_recon" || rc=1
    applied=1
  fi
  if ! apply_php_types_intersection_type_reconstructor_overlay_to_target "$vendor_recon"; then
    rc=1
  fi
  if ! apply_php_types_intersection_type_reconstructor_overlay_to_target "$prelinked_recon"; then
    rc=1
  fi
  if [[ "$applied" -eq 0 ]]; then
    echo "Skip php-types-union-type.patch (already applied)"
  else
    echo "Applied php-types-union-type.patch (overlay)"
  fi
  return "$rc"
}

apply_php_types_closure_unbound_this_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  if grep -q "is_string(\$op->extra->value) && '' !== \$op->extra->value" "$target" 2>/dev/null; then
    echo "Skip php-types-closure-unbound-this.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old1 = """            } elseif ($op instanceof Operand\\BoundVariable && $op->scope === Operand\\BoundVariable::SCOPE_OBJECT) {
                $resolved[$op] = $op->type = Type::fromDecl($op->extra->value);
            } elseif ($op instanceof Operand\\Literal) {"""
new1 = """            } elseif ($op instanceof Operand\\BoundVariable && $op->scope === Operand\\BoundVariable::SCOPE_OBJECT) {
                if ($op->extra instanceof Operand\\Literal && is_string($op->extra->value) && '' !== $op->extra->value) {
                    $resolved[$op] = $op->type = Type::fromDecl($op->extra->value);
                } else {
                    $resolved[$op] = $op->type = Type::unknown();
                }
            } elseif ($op instanceof Operand\\Literal) {"""
old2 = """        if ($var instanceof Operand\\BoundVariable && $var->scope === Operand\\BoundVariable::SCOPE_OBJECT) {
            assert($var->extra instanceof Operand\\Literal);

            return Type::fromDecl($var->extra->value);
        }"""
new2 = """        if ($var instanceof Operand\\BoundVariable && $var->scope === Operand\\BoundVariable::SCOPE_OBJECT) {
            if ($var->extra instanceof Operand\\Literal && is_string($var->extra->value) && '' !== $var->extra->value) {
                return Type::fromDecl($var->extra->value);
            }

            return Type::unknown();
        }"""
if old1 in text and old2 in text:
    path.write_text(text.replace(old1, new1, 1).replace(old2, new2, 1))
    raise SystemExit(0)
sys.stderr.write("php-types-closure-unbound-this: TypeReconstructor anchor not found\n")
raise SystemExit(1)
PY
  echo "Applied php-types-closure-unbound-this.patch (overlay)"
}

repair_php_types_fcc_type_array_typo_in_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    return 0
  fi
  if grep -q 'return \[Type::array()\];' "$target" 2>/dev/null; then
    sed -i 's/return \[Type::array()\];/return [new Type(Type::TYPE_ARRAY)];/' "$target"
    echo "Repaired php-types-first-class-callable Type::array() typo in ${target} (#4957, #6932)"
  fi
}

apply_php_types_fcc_overlay_final_repair() {
  repair_php_types_fcc_type_array_typo_in_target "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  repair_php_types_fcc_type_array_typo_in_target "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
}

apply_php_types_first_class_callable_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  repair_php_types_fcc_type_array_typo_in_target "$target"
  if grep -q 'FirstClassCallable::KIND_METHOD' "$target" 2>/dev/null; then
    echo "Skip php-types-first-class-callable.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
fcc_case = """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
"""
anchors = [
    (
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
            case 'Expr_MagicScriptConst':""",
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + fcc_case + """            case 'Expr_MagicScriptConst':""",
    ),
    (
        """            case 'Expr_MagicScriptConst':""",
        fcc_case + """            case 'Expr_MagicScriptConst':""",
    ),
    (
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
            case 'Expr_PostInc':""",
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + fcc_case + """
            case 'Expr_PostInc':""",
    ),
    (
        """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;

            case 'Expr_PostInc':""",
        """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + fcc_case + """
            case 'Expr_PostInc':""",
    ),
    (
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
        }

        throw new \\LogicException('Unknown variable op found: '.$op->getType());""",
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + fcc_case + """        }

        throw new \\LogicException('Unknown variable op found: '.$op->getType());""",
    ),
]
for old, new in anchors:
    if old in text:
        path.write_text(text.replace(old, new, 1))
        raise SystemExit(0)
sys.stderr.write("php-types-first-class-callable: TypeReconstructor anchor not found\n")
raise SystemExit(1)
PY
  echo "Applied php-types-first-class-callable.patch (overlay)"
}

apply_php_types_magic_script_const_overlay_to_target() {
  local target="$1"
  [[ -f "$target" ]] || return 0
  if grep -q 'MagicScriptConst::KIND_LINE' "$target" 2>/dev/null; then
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
msc_case = """            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
"""
anchors = [
    (
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
            case 'Expr_PostInc':""",
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + msc_case + """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
            case 'Expr_PostInc':""",
    ),
    (
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
            case 'Expr_PostInc':""",
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + msc_case + """            case 'Expr_PostInc':""",
    ),
    (
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;

            case 'Expr_PostInc':""",
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + msc_case + """
            case 'Expr_PostInc':""",
    ),
    (
        """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
            case 'Expr_PostInc':""",
        """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + msc_case + """            case 'Expr_PostInc':""",
    ),
    (
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
        }

        throw new \\LogicException('Unknown variable op found: '.$op->getType());""",
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + msc_case + """        }

        throw new \\LogicException('Unknown variable op found: '.$op->getType());""",
    ),
    (
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
        }

        throw new \\LogicException('Unknown variable op found: '.$op->getType());""",
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
""" + msc_case + """        }

        throw new \\LogicException('Unknown variable op found: '.$op->getType());""",
    ),
]
for old, new in anchors:
    if old in text:
        path.write_text(text.replace(old, new, 1))
        raise SystemExit(0)
sys.stderr.write("php-types-magic-script-const: TypeReconstructor anchor not found in " + sys.argv[1] + "\n")
raise SystemExit(1)
PY
}

apply_php_types_magic_script_const_overlay() {
  local vendor="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local prelinked="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  if grep -q 'MagicScriptConst::KIND_LINE' "$vendor" 2>/dev/null \
    && { [[ ! -f "$prelinked" ]] || grep -q 'MagicScriptConst::KIND_LINE' "$prelinked" 2>/dev/null; }; then
    echo "Skip php-types-magic-script-const.patch (already applied)"
    return 0
  fi
  apply_php_types_magic_script_const_overlay_to_target "$vendor" \
    || return 1
  apply_php_types_magic_script_const_overlay_to_target "$prelinked" \
    || return 1
  echo "Applied php-types-magic-script-const.patch (overlay)"
}

apply_php_types_resolver_worklist_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    return 0
  fi
  if grep -q 'PHPTYPES_RESOLVER_LEGACY' "$target" 2>/dev/null; then
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()

if "PHPTYPES_RESOLVER_LEGACY" in text:
    raise SystemExit(0)

legacy_header = """        // Worklist is the default (#16077 / #36225): dependency-driven instead of
        // O(rounds × vars) rescans. Opcode dumps match legacy on a 10-file corpus.
        // Opt out with PHPTYPES_RESOLVER_WORKLIST=0 or PHPTYPES_RESOLVER_LEGACY=1.
        $worklistFlag = getenv('PHPTYPES_RESOLVER_WORKLIST');
        $legacyResolver = ('0' === $worklistFlag)
            || ('1' === getenv('PHPTYPES_RESOLVER_LEGACY'))
            || ('false' === strtolower((string) $worklistFlag));
        if ($legacyResolver) {"""

old_optin = "        if ('1' !== getenv('PHPTYPES_RESOLVER_WORKLIST')) {"
old_optin_else_comment = """            // Dependency-driven worklist: the round-based loop rescanned every
            // unresolved variable per round — O(rounds × vars), minutes on
            // 30k-line files (#16077). Resolution ORDER differs from the round
            // loop and the fixpoint is order-sensitive for codegen, so this is
            // opt-in for lint workloads only (bin/lint.php sets the flag);
            // default stays on the legacy round loop."""
new_worklist_comment = """            // Dependency-driven worklist: the round-based loop rescanned every
            // unresolved variable per round — O(rounds × vars), minutes on
            // 30k-line files (#16077)."""

worklist_else = """        } else {
            // Dependency-driven worklist: the round-based loop rescanned every
            // unresolved variable per round — O(rounds × vars), minutes on
            // 30k-line files (#16077).
            $dependents = new SplObjectStorage();
            foreach ($unresolved as $var) {
                foreach ($var->ops as $op) {
                    foreach ($op->getVariableNames() as $name) {
                        if ($op->isWriteVariable($name)) {
                            continue;
                        }
                        $inputs = $op->{$name};
                        if (! is_array($inputs)) {
                            $inputs = [$inputs];
                        }
                        foreach ($inputs as $input) {
                            if (! $input instanceof Operand || $input === $var) {
                                continue;
                            }
                            if (! isset($dependents[$input])) {
                                $dependents[$input] = [];
                            }
                            $deps = $dependents[$input];
                            $deps[] = $var;
                            $dependents[$input] = $deps;
                        }
                    }
                }
            }
            $queue = [];
            foreach ($unresolved as $var) {
                $queue[] = $var;
            }
            $queued = new SplObjectStorage();
            foreach ($queue as $var) {
                $queued->attach($var);
            }
            // Head-index pop: array_shift() reindexes (O(n) per pop, quadratic
            // on large queues); appends keep integer keys sequential.
            $head = 0;
            while (isset($queue[$head])) {
                $var = $queue[$head];
                unset($queue[$head]);
                ++$head;
                $queued->detach($var);
                if (! $unresolved->contains($var)) {
                    continue;
                }
                $type = $this->resolveVar($var, $resolved);
                if (! $type) {
                    continue;
                }
                $resolved[$var] = $type;
                $unresolved->detach($var);
                if (isset($dependents[$var])) {
                    foreach ($dependents[$var] as $dep) {
                        if ($unresolved->contains($dep) && ! $queued->contains($dep)) {
                            $queue[] = $dep;
                            $queued->attach($dep);
                        }
                    }
                }
            }
        }"""

round_loop = """        $round = 1;
        do {
            $start = count($resolved);
            $toRemove = [];
            foreach ($unresolved as $k => $var) {
                $type = $this->resolveVar($var, $resolved);
                if ($type) {
                    $toRemove[] = $var;
                    $resolved[$var] = $type;
                }
            }
            foreach ($toRemove as $remove) {
                $unresolved->detach($remove);
            }
        } while (count($unresolved) > 0 && $start < count($resolved));"""

if old_optin in text:
    text = text.replace(old_optin, legacy_header, 1)
    text = text.replace(old_optin_else_comment, new_worklist_comment, 1)
elif "$dependents = new SplObjectStorage()" not in text:
    if round_loop not in text:
        sys.stderr.write("php-types-resolver-worklist: round-loop anchor not found\n")
        raise SystemExit(1)
    legacy_round = round_loop.replace(
        "        $round = 1;",
        legacy_header + "\n            $round = 1;",
        1,
    )
    text = text.replace(round_loop, legacy_round + worklist_else, 1)
else:
    sys.stderr.write("php-types-resolver-worklist: unknown TypeReconstructor state\n")
    raise SystemExit(1)

if "PHPTYPES_RESOLVER_LEGACY" not in text:
    sys.stderr.write("php-types-resolver-worklist: flip did not apply\n")
    raise SystemExit(1)

path.write_text(text)
PY
  then
    return 1
  fi
  return 0
}

apply_php_types_resolver_worklist_overlay() {
  local rc=0
  local vendor="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local prelinked="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local applied=0

  if [[ -f "$vendor" ]] && ! grep -q 'PHPTYPES_RESOLVER_LEGACY' "$vendor" 2>/dev/null; then
    if grep -q "PHPTYPES_RESOLVER_WORKLIST" "$vendor" 2>/dev/null \
      || ! grep -q '\$dependents = new SplObjectStorage' "$vendor" 2>/dev/null; then
      apply_php_types_resolver_worklist_overlay_to_target "$vendor" || rc=1
      applied=1
    fi
  fi
  if [[ -f "$prelinked" ]] && ! grep -q 'PHPTYPES_RESOLVER_LEGACY' "$prelinked" 2>/dev/null; then
    if ! grep -q '\$dependents = new SplObjectStorage' "$prelinked" 2>/dev/null \
      || grep -q "PHPTYPES_RESOLVER_WORKLIST" "$prelinked" 2>/dev/null; then
      apply_php_types_resolver_worklist_overlay_to_target "$prelinked" || rc=1
      applied=1
    fi
  fi
  if [[ "$applied" -eq 0 ]]; then
    echo "Skip php-types-resolver-worklist.patch (already applied)"
  else
    echo "Applied php-types-resolver-worklist.patch (overlay)"
  fi
  return "$rc"
}


apply_php_types_incdec_type_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    echo "Skip php-types-incdec-type.patch (target missing): ${target}"
    return 0
  fi
  if grep -q "case 'Expr_PostInc':" "$target" 2>/dev/null; then
    echo "Skip php-types-incdec-type.patch (already applied): ${target}"
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
incdec_case = """
            case 'Expr_PostInc':
            case 'Expr_PostDec':
            case 'Expr_PreInc':
            case 'Expr_PreDec':
                if ($resolved->contains($op->read)) {
                    return [$resolved[$op->read]];
                }

                return false;
"""
throw_tail = "        throw new \\LogicException('Unknown variable op found: '.$op->getType());"
anchors = [
    (
        """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
        }

""" + throw_tail,
        """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + incdec_case + """        }

""" + throw_tail,
    ),
    (
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];

        }

""" + throw_tail,
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
""" + incdec_case + """        }

""" + throw_tail,
    ),
    (
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
        }

""" + throw_tail,
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
""" + incdec_case + """        }

""" + throw_tail,
    ),
    (
        """            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind
                    || \\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_HALT_OFFSET === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
        }

""" + throw_tail,
        """            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind
                    || \\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_HALT_OFFSET === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
""" + incdec_case + """        }

""" + throw_tail,
    ),
    (
        """            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
        }

""" + throw_tail,
        """            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
""" + incdec_case + """        }

""" + throw_tail,
    ),
    (
        """            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];

        }

""" + throw_tail,
        """            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
""" + incdec_case + """        }

""" + throw_tail,
    ),
    (
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
        }

""" + throw_tail,
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
""" + incdec_case + """        }

""" + throw_tail,
    ),
]
for old, new in anchors:
    if old in text:
        path.write_text(text.replace(old, new, 1))
        raise SystemExit(0)
sys.stderr.write("php-types-incdec-type: TypeReconstructor switch marker not found\\n")
raise SystemExit(1)
PY
  then
    echo "ERROR: php-types-incdec-type overlay failed for ${target} (#6326, #6321)" >&2
    return 1
  fi
  echo "Applied php-types-incdec-type.patch (overlay): ${target}"
}

# #30793 / #30807: (object) on CFG userType resource must not preserve Resource —
# Zend convert_to_object always yields stdClass::$scalar. Line numbers drift between
# vendor/ and prelinked/, so use a text overlay (the .patch file is documentation +
# git-apply fallback for fresh vendor only).
apply_php_types_cast_object_resource_stdclass_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    echo "Skip php-types-cast-object-resource-stdclass.patch (target missing): ${target}"
    return 0
  fi
  if grep -q 'VM Resource wrappers are TYPE_OBJECT but Zend IS_RESOURCE' "$target" 2>/dev/null; then
    echo "Skip php-types-cast-object-resource-stdclass.patch (already applied): ${target}"
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
# PHP source needs '\\' (two chars) as ltrim charset for one backslash.
bs = "\\"
old = (
    "            if ($exprType instanceof Type && $exprType->type === Type::TYPE_OBJECT) {\n"
    "                return [$resolved[$op->expr]];\n"
    "            }\n"
    "\n"
    "            return [new Type(Type::TYPE_OBJECT, [], 'stdClass')];\n"
)
new = (
    "            if ($exprType instanceof Type && $exprType->type === Type::TYPE_OBJECT) {\n"
    "                // VM Resource wrappers are TYPE_OBJECT but Zend IS_RESOURCE - (object) still\n"
    "                // yields stdClass::$scalar (#30793, zend_operators.c convert_to_object).\n"
    "                $user = strtolower(ltrim((string) ($exprType->userType ?? ''), '" + bs + bs + "'));\n"
    "                if ('resource' !== $user) {\n"
    "                    return [$resolved[$op->expr]];\n"
    "                }\n"
    "            }\n"
    "\n"
    "            return [new Type(Type::TYPE_OBJECT, [], 'stdClass')];\n"
)
idx = text.find('function resolveOp_Expr_Cast_Object')
if idx < 0:
    sys.stderr.write("php-types-cast-object-resource-stdclass: Cast_Object missing\n")
    raise SystemExit(1)
end = text.find('function resolveOp_Expr_', idx + 10)
region = text[idx:end] if end > 0 else text[idx:]
if old not in region:
    sys.stderr.write("php-types-cast-object-resource-stdclass: Cast_Object TYPE_OBJECT arm not found\n")
    raise SystemExit(1)
region2 = region.replace(old, new, 1)
path.write_text(text[:idx] + region2 + (text[end:] if end > 0 else ''))
PY
  then
    echo "ERROR: php-types-cast-object-resource-stdclass overlay failed for ${target}" >&2
    return 1
  fi
  echo "Applied php-types-cast-object-resource-stdclass.patch (overlay): ${target}"
}

apply_php_types_cast_object_resource_stdclass_overlay() {
  local rc=0
  if ! apply_php_types_cast_object_resource_stdclass_overlay_to_target "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"; then
    rc=1
  fi
  if ! apply_php_types_cast_object_resource_stdclass_overlay_to_target "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"; then
    rc=1
  fi
  if [[ "$rc" -ne 0 ]]; then
    record_patch_failure "php-types-cast-object-resource-stdclass.patch" "Cast_Object resource->stdClass arm missing (#30793)"
  fi
  return "$rc"
}

apply_php_types_incdec_type_overlay() {
  local rc=0
  if ! apply_php_types_incdec_type_overlay_to_target "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"; then
    rc=1
  fi
  if ! apply_php_types_incdec_type_overlay_to_target "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"; then
    rc=1
  fi
  if [[ "$rc" -ne 0 ]]; then
    record_patch_failure "php-types-incdec-type.patch" "PostInc TypeReconstructor arms missing — fix overlay anchors (#6321)"
  fi
  return "$rc"
}

apply_php_types_yield_from_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  if grep -q "case 'Expr_YieldFrom':" "$target" 2>/dev/null; then
    echo "Skip php-types-yield-from.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
anchor = """            case 'Expr_Yield':
            case 'Expr_Include':"""
if anchor not in text:
    sys.stderr.write("php-types-yield-from: TypeReconstructor Expr_Yield anchor not found\n")
    raise SystemExit(1)
insert = """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':"""
path.write_text(text.replace(anchor, insert, 1))
PY
  echo "Applied php-types-yield-from.patch (overlay)"
}

apply_php_types_throw_expr_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    echo "Skip php-types-throw-expr.patch (target missing): ${target}"
    return 0
  fi
  if grep -q "case 'Expr_Throw':" "$target" 2>/dev/null; then
    if grep -A1 "case 'Expr_Throw':" "$target" 2>/dev/null | grep -q 'Type::never()'; then
      echo "Skip php-types-throw-expr.patch (already applied): ${target}"
      return 0
    fi
    # Upgrade legacy fall-through (Expr_Exit + Expr_Throw → null) to never (#6746).
    if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = """            case 'Expr_Exit':
            case 'Expr_Throw':
            case 'Iterator_Reset':
                return [Type::null()];"""
new = """            case 'Expr_Exit':
            case 'Iterator_Reset':
                return [Type::null()];
            case 'Expr_Throw':
                return [Type::never()];"""
if old not in text:
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
    then
      echo "Skip php-types-throw-expr.patch (already applied): ${target}"
      return 0
    fi
    echo "Applied php-types-throw-expr.patch (never upgrade): ${target}"
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
anchors = [
    (
        """            case 'Expr_Exit':
            case 'Iterator_Reset':
                return [Type::null()];""",
        """            case 'Expr_Exit':
            case 'Iterator_Reset':
                return [Type::null()];
            case 'Expr_Throw':
                return [Type::never()];""",
    ),
    (
        """            case 'Expr_Exit':
            case 'Expr_Throw':
            case 'Iterator_Reset':
                return [Type::null()];""",
        """            case 'Expr_Exit':
            case 'Iterator_Reset':
                return [Type::null()];
            case 'Expr_Throw':
                return [Type::never()];""",
    ),
    (
        """            case 'Expr_Exit':
                return [Type::null()];
            case 'Iterator_Reset':""",
        """            case 'Expr_Exit':
                return [Type::null()];
            case 'Expr_Throw':
                return [Type::never()];
            case 'Iterator_Reset':""",
    ),
]
for old, new in anchors:
    if old in text:
        path.write_text(text.replace(old, new, 1))
        raise SystemExit(0)

sys.stderr.write("php-types-throw-expr: TypeReconstructor Expr_Exit anchor not found\n")
raise SystemExit(1)
PY
  then
    echo "ERROR: php-types-throw-expr overlay failed for ${target} (#5151)" >&2
    return 1
  fi
  echo "Applied php-types-throw-expr.patch (overlay): ${target}"
}

apply_php_types_throw_expr_overlay() {
  local rc=0
  if ! apply_php_types_throw_expr_overlay_to_target "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"; then
    rc=1
  fi
  if ! apply_php_types_throw_expr_overlay_to_target "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"; then
    rc=1
  fi
  if [[ "$rc" -ne 0 ]]; then
    record_patch_failure "php-types-throw-expr.patch" "Expr_Throw TypeReconstructor arm missing — fix overlay anchors (#5151)"
  fi
  return "$rc"
}

apply_php_types_never_method_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    echo "Skip php-types-never-type.patch (target missing): ${target}"
    return 0
  fi
  if grep -q 'function never(): self' "$target" 2>/dev/null \
    && grep -q 'instanceof CfgType\\Never_' "$target" 2>/dev/null \
    && grep -q "case 'never':" "$target" 2>/dev/null; then
    echo "Skip php-types-never-type.patch (already applied): ${target}"
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
changed = False

if 'function never(): self' not in text:
    anchor = """    public static function null(): self
    {
        return self::makeCachedType(self::TYPE_NULL);
    }

    public static function object(): self"""
    insert = """    public static function null(): self
    {
        return self::makeCachedType(self::TYPE_NULL);
    }

    public static function never(): self
    {
        return self::makeCachedType(self::TYPE_NULL);
    }

    public static function object(): self"""
    if anchor not in text:
        raise SystemExit(1)
    text = text.replace(anchor, insert, 1)
    changed = True

if 'instanceof CfgType\\Never_' not in text:
    never_decl = """        if ($decl instanceof CfgType\\Never_) {
            return self::never();
        }
"""
    decl_anchors = [
        ("""        if ($decl instanceof CfgType\\Literal) {
            return self::fromDecl($decl->name);
        }
        if ($decl instanceof CfgType\\Mixed_) {""",
         """        if ($decl instanceof CfgType\\Literal) {
            return self::fromDecl($decl->name);
        }
""" + never_decl + """        if ($decl instanceof CfgType\\Mixed_) {"""),
        ("""        if ($decl instanceof CfgType\\Mixed_) {
            return self::mixed();
        }""",
         never_decl + """        if ($decl instanceof CfgType\\Mixed_) {
            return self::mixed();
        }"""),
        ("""        if ($decl instanceof CfgType\\Literal) {
            return self::fromDecl($decl->name);
        }

        throw new \\LogicException('Unsupported declaration type: '.get_class($decl));""",
         """        if ($decl instanceof CfgType\\Literal) {
            return self::fromDecl($decl->name);
        }
""" + never_decl + """
        throw new \\LogicException('Unsupported declaration type: '.get_class($decl));"""),
    ]
    for anchor, insert in decl_anchors:
        if anchor in text:
            text = text.replace(anchor, insert, 1)
            changed = True
            break
    else:
        raise SystemExit(2)

if "case 'never':" not in text:
    anchor = """            case 'null':
            case 'void':
                return new self(self::TYPE_NULL);
            case 'numeric':"""
    insert = """            case 'null':
            case 'void':
                return new self(self::TYPE_NULL);
            case 'never':
                return self::never();
            case 'numeric':"""
    if anchor not in text:
        raise SystemExit(3)
    text = text.replace(anchor, insert, 1)
    changed = True

if not changed:
    raise SystemExit(4)

path.write_text(text)
PY
  then
    echo "ERROR: php-types-never-type overlay failed for ${target} (#4137/#7329)" >&2
    return 1
  fi
  echo "Applied php-types-never-type.patch (never overlay): ${target}"
}

apply_php_types_never_type_reconstructor_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    echo "Skip php-types-never-type.patch TypeReconstructor (target missing): ${target}"
    return 0
  fi
  if grep -q 'instanceof Op\\Type\\Never_' "$target" 2>/dev/null; then
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
never_arm = """        } elseif ($type instanceof Op\\Type\\Never_) {
            return Type::never();
"""
anchors = [
    ("""        } elseif ($type instanceof Op\\Type\\Literal) {
            return Type::fromDecl($type->name);
        } elseif ($type instanceof Op\\Type\\Union_) {""",
     """        } elseif ($type instanceof Op\\Type\\Literal) {
            return Type::fromDecl($type->name);
""" + never_arm + """        } elseif ($type instanceof Op\\Type\\Union_) {"""),
    ("""        } elseif ($type instanceof Op\\Type\\Literal) {
            return Type::fromDecl($type->name);
        }

        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));""",
     """        } elseif ($type instanceof Op\\Type\\Literal) {
            return Type::fromDecl($type->name);
""" + never_arm + """        }

        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));"""),
]
for old, new in anchors:
    if old in text:
        path.write_text(text.replace(old, new, 1))
        raise SystemExit(0)
sys.stderr.write("php-types-never-type: TypeReconstructor Never_ anchor not found\n")
raise SystemExit(1)
PY
  then
    echo "ERROR: php-types-never-type TypeReconstructor overlay failed for ${target} (#4137/#7329)" >&2
    return 1
  fi
  echo "Applied php-types-never-type.patch (TypeReconstructor overlay): ${target}"
}

apply_php_types_never_type_overlay() {
  local rc=0
  if ! apply_php_types_never_method_overlay_to_target "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"; then
    rc=1
  fi
  if ! apply_php_types_never_method_overlay_to_target "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/Type.php"; then
    rc=1
  fi
  if ! apply_php_types_never_type_reconstructor_overlay_to_target "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"; then
    rc=1
  fi
  if ! apply_php_types_never_type_reconstructor_overlay_to_target "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"; then
    rc=1
  fi
  if [[ "$rc" -ne 0 ]]; then
    record_patch_failure "php-types-never-type.patch" "Type::never() missing — fix overlay anchors (#4137)"
  fi
  return "$rc"
}

apply_php_types_never_method_overlay() {
  apply_php_types_never_type_overlay
}

# Revert phantom hex2bin $strict from InternalArgInfo (#27763 — php-src arity 1 only).
apply_php_types_hex2bin_strict_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php"
  if grep -q "'hex2bin' => \['string', 'data' => 'string'\]," "$target" 2>/dev/null \
    && ! grep -q "'hex2bin' => \['string', 'data' => 'string', 'strict=' => 'bool'\]" "$target" 2>/dev/null; then
    echo "Skip php-types-hex2bin-strict-revert (already arity 1)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = "        'hex2bin' => ['string', 'data' => 'string', 'strict=' => 'bool'],\n"
new = "        'hex2bin' => ['string', 'data' => 'string'],\n"
if old not in text:
    if new in text:
        raise SystemExit(0)
    sys.stderr.write("php-types-hex2bin-strict-revert: hex2bin anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-types-hex2bin-strict-revert (overlay, #27763)"
}
apply_php_types_str_bool_fns_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php"
  if grep -q "'str_contains' => \['bool'" "$target" 2>/dev/null; then
    echo "Skip php-types-str-bool-fns.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
needle = "        'strspn' => ['int', 'str' => 'string', 'mask' => 'string', 'start=' => 'int', 'len=' => 'int'],\n"
insert = needle + (
    "        'str_contains' => ['bool', 'haystack' => 'string', 'needle' => 'string'],\n"
    "        'str_ends_with' => ['bool', 'haystack' => 'string', 'needle' => 'string'],\n"
    "        'str_starts_with' => ['bool', 'haystack' => 'string', 'needle' => 'string'],\n"
)
if needle not in text:
    sys.stderr.write("php-types-str-bool-fns: strspn anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(needle, insert, 1))
PY
  echo "Applied php-types-str-bool-fns.patch (overlay)"
}

apply_php_types_docblock_trailing_text_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if patch_already_applied "$PATCH_DIR/php-types-docblock-trailing-text.patch"; then
    echo "Skip php-types-docblock-trailing-text.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()

if 'stripTrailingDocText' not in text:
    anchor = "        }\n        switch (strtolower($decl)) {"
    if anchor not in text:
        sys.stderr.write("php-types-docblock-trailing-text: fromDecl switch anchor not found\n")
        raise SystemExit(1)
    insert = "        }\n        $decl = self::stripTrailingDocText($decl);\n        switch (strtolower($decl)) {"
    text = text.replace(anchor, insert, 1)

    class_end = "\n}\n"
    if not text.endswith(class_end):
        sys.stderr.write("php-types-docblock-trailing-text: expected Type.php to end with class brace\n")
        raise SystemExit(1)
    helper = """
    private static function stripTrailingDocText(string $decl): string
    {
        $decl = trim($decl);
        if ('' === $decl) {
            return $decl;
        }
        if (false === strpos($decl, ' ')) {
            return $decl;
        }

        $depthAngle = 0;
        $depthParen = 0;
        $depthSquare = 0;
        $depthCurly = 0;

        $len = strlen($decl);
        for ($i = 0; $i < $len; $i++) {
            $ch = $decl[$i];
            switch ($ch) {
                case '<':
                    $depthAngle++;
                    break;
                case '>':
                    if ($depthAngle > 0) {
                        $depthAngle--;
                    }
                    break;
                case '(':
                    $depthParen++;
                    break;
                case ')':
                    if ($depthParen > 0) {
                        $depthParen--;
                    }
                    break;
                case '[':
                    $depthSquare++;
                    break;
                case ']':
                    if ($depthSquare > 0) {
                        $depthSquare--;
                    }
                    break;
                case '{':
                    $depthCurly++;
                    break;
                case '}':
                    if ($depthCurly > 0) {
                        $depthCurly--;
                    }
                    break;
                default:
                    if ($ch <= ' ' && 0 === $depthAngle && 0 === $depthParen && 0 === $depthSquare && 0 === $depthCurly) {
                        // callable(T): R — space after ':' is return type, not trailing prose (#8559 spine).
                        if ($i > 0 && ':' === $decl[$i - 1]) {
                            break;
                        }
                        return trim(substr($decl, 0, $i));
                    }
                    break;
            }
        }

        return $decl;
    }
"""
    text = text[: -len(class_end)] + helper + class_end

path.write_text(text)
PY
  echo "Applied php-types-docblock-trailing-text.patch (overlay)"
}

apply_php_types_callable_return_strip_overlay_to_target() {
  local target="$1"
  [[ -f "$target" ]] || return 0
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
needle = """                    if ($ch <= ' ' && 0 === $depthAngle && 0 === $depthParen && 0 === $depthSquare && 0 === $depthCurly) {
                        return trim(substr($decl, 0, $i));
                    }"""
replacement = """                    if ($ch <= ' ' && 0 === $depthAngle && 0 === $depthParen && 0 === $depthSquare && 0 === $depthCurly) {
                        // callable(T): R — space after ':' is return type, not trailing prose (#8559 spine).
                        if ($i > 0 && ':' === $decl[$i - 1]) {
                            break;
                        }
                        return trim(substr($decl, 0, $i));
                    }"""
if 'callable(T): R' not in text:
    if needle not in text:
        sys.stderr.write("php-types-callable-return-strip: stripTrailingDocText anchor not found\n")
        raise SystemExit(1)
    text = text.replace(needle, replacement, 1)

fromdecl_block = (
    "        // Docblock callable / Closure(T):R signatures — vendor only supports bare forms (#8559, #36382 Composer ClassLoader).\n"
    "        if (preg_match('/^(?:\\\\\\\\)?callable\\s*\\(/i', $decl)) {\n"
    "            return new self(self::TYPE_CALLABLE);\n"
    "        }\n"
    "        if (preg_match('/^(?:\\\\\\\\)?Closure\\s*\\(/i', $decl)) {\n"
    "            return new self(self::TYPE_OBJECT, [], 'Closure');\n"
    "        }\n"
)
old_callable_only = (
    "        // Docblock callable signatures: vendor only supports bare callable keyword (#8559 spine).\n"
    "        if (preg_match('/^callable\\s*\\(/i', $decl)) {\n"
    "            return new self(self::TYPE_CALLABLE);\n"
    "        }\n"
)
if 'Closure(T):R signatures' not in text:
    if old_callable_only in text:
        text = text.replace(old_callable_only, fromdecl_block, 1)
    else:
        fromdecl_needle = "        // Docblock union splits may leave a lone \"string,\" fragment (M2 spine; #3012).\n"
        fromdecl_alt_needle = "        switch (strtolower($decl)) {\n"
        if fromdecl_needle in text:
            text = text.replace(fromdecl_needle, fromdecl_block + fromdecl_needle, 1)
        elif fromdecl_alt_needle in text:
            text = text.replace(fromdecl_alt_needle, fromdecl_block + fromdecl_alt_needle, 1)
        else:
            sys.stderr.write("php-types-callable-return-strip: fromDecl anchor not found\n")
            raise SystemExit(1)

path.write_text(text)
PY
}

apply_php_types_callable_return_strip_overlay() {
  if patch_already_applied "$PATCH_DIR/php-types-callable-return-strip.patch"; then
    echo "Skip php-types-callable-return-strip.patch (already applied)"
    return 0
  fi
  apply_php_types_callable_return_strip_overlay_to_target \
    "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  apply_php_types_callable_return_strip_overlay_to_target \
    "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/Type.php"
  echo "Applied php-types-callable-return-strip.patch (overlay)"
}

apply_php_types_docblock_full_type_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if patch_already_applied "$PATCH_DIR/php-types-docblock-first-token.patch"; then
    echo "Skip php-types-docblock-first-token.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
replacements = [
    (
        "                if (preg_match('(@var\\s+(\\S+))', $comment, $match)) {",
        "                if (preg_match('(@var\\s+(.+?)(?:\\s*\\*\\/|\\s*$))m', $comment, $match)) {",
    ),
    (
        "                if (preg_match('(@var\\s+([^\\s*][^\\s]*))', $comment, $match)) {",
        "                if (preg_match('(@var\\s+(.+?)(?:\\s*\\*\\/|\\s*$))m', $comment, $match)) {",
    ),
    (
        "                if (preg_match('(@return\\s+(\\S+))', $comment, $match)) {",
        "                if (preg_match('(@return\\s+(.+?)(?:\\s*\\*\\/|\\s*$))m', $comment, $match)) {",
    ),
    (
        "                if (preg_match('(@return\\s+([^\\s*][^\\s]*))', $comment, $match)) {",
        "                if (preg_match('(@return\\s+(.+?)(?:\\s*\\*\\/|\\s*$))m', $comment, $match)) {",
    ),
]
for old, new in replacements:
    if old in text:
        path.write_text(text.replace(old, new, 1))
        raise SystemExit(0)
sys.stderr.write("php-types-docblock-first-token: extractTypeFromComment anchor not found\n")
raise SystemExit(1)
PY
  echo "Applied php-types-docblock-first-token.patch (overlay)"
}


php_types_type_fromdecl_trailing_comma_corrupt() {
  local target="$1"
  [[ -f "$target" ]] || return 1
  grep -q 'Docblock union splits.*\\n        \$trimmedDecl' "$target" 2>/dev/null
}

apply_php_types_fromdecl_trailing_comma_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if patch_already_applied "$PATCH_DIR/php-types-fromdecl-trailing-comma.patch"; then
    echo "Skip php-types-fromdecl-trailing-comma.patch (already applied)"
    return 0
  fi
  if php_types_type_fromdecl_trailing_comma_corrupt "$target"; then
    echo "Repair php-types-fromdecl-trailing-comma.patch (literal \\\\n corruption; #9261)"
  fi
  python3 - "$target" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()

corrupt = re.compile(
    r"\n        // Docblock union splits may leave a lone[^\n]*\\n[^\n]*$",
    re.MULTILINE,
)
text, removed = corrupt.subn("\n", text, count=1)
if removed:
    path.write_text(text)

needle = "        $decl = self::stripTrailingDocText($decl);\n"
if needle not in text:
    sys.stderr.write("php-types-fromdecl-trailing-comma: stripTrailingDocText anchor not found\n")
    sys.exit(1)

insertion = needle + (
    "        // Docblock union splits may leave a lone \"string,\" fragment (M2 spine; #3012).\n"
    "        $trimmedDecl = trim($decl);\n"
    "        if (str_ends_with($trimmedDecl, ',') && !str_contains($trimmedDecl, '|') && !str_contains($trimmedDecl, '&')) {\n"
    "            return self::fromDecl(rtrim($trimmedDecl, ', '));\n"
    "        }\n"
)

if 'Docblock union splits may leave a lone' in text:
    if re.search(r"if \(str_ends_with\(\$trimmedDecl, ','\)", text):
        raise SystemExit(0)

path.write_text(text.replace(needle, insertion, 1))
PY
  echo "Applied php-types-fromdecl-trailing-comma.patch (overlay)"
}

apply_php_types_generic_null_tail_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if patch_already_applied "$PATCH_DIR/php-types-generic-null-tail.patch"; then
    echo "Skip php-types-generic-null-tail.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
needle = "        $decl = self::stripTrailingDocText($decl);\n"
insert = needle + (
    "        $trimmedDecl = trim($decl);\n"
    "        // list<T|null> union splits may pass a trailing \"null>\" fragment (#2276).\n"
    "        if (str_ends_with($trimmedDecl, '>') && !str_contains($trimmedDecl, '<')) {\n"
    "            $trimmedDecl = rtrim(substr($trimmedDecl, 0, -1));\n"
    "            $decl = $trimmedDecl;\n"
    "        }\n"
)
if needle not in text:
    sys.stderr.write("php-types-generic-null-tail: stripTrailingDocText line not found\n")
    raise SystemExit(1)
if 'list<T|null> union splits' in text:
    raise SystemExit(0)
path.write_text(text.replace(needle, insert, 1))
PY
  echo "Applied php-types-generic-null-tail.patch (overlay)"
}

apply_php_types_remove_type_empty_union_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    return 0
  fi
  if ! grep -q "throw new \\\\LogicException('Unknown type encountered')" "$target" 2>/dev/null; then
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = "            throw new \\LogicException('Unknown type encountered');"
new = "            return self::mixed();"
if old not in text:
    raise SystemExit(0)
path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-types-remove-type-empty-union.patch (Type.php overlay): ${target}"
}

apply_php_types_remove_type_empty_union_overlay() {
  local rc=0
  local vendor="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  local prelinked="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/Type.php"
  local applied=0

  if [[ -f "$vendor" ]] && grep -q "throw new \\\\LogicException('Unknown type encountered')" "$vendor" 2>/dev/null; then
    apply_php_types_remove_type_empty_union_overlay_to_target "$vendor" || rc=1
    applied=1
  fi
  if [[ -f "$prelinked" ]] && grep -q "throw new \\\\LogicException('Unknown type encountered')" "$prelinked" 2>/dev/null; then
    apply_php_types_remove_type_empty_union_overlay_to_target "$prelinked" || rc=1
    applied=1
  fi
  if [[ "$applied" -eq 0 ]]; then
    echo "Skip php-types-remove-type-empty-union.patch (already applied)"
  else
    echo "Applied php-types-remove-type-empty-union.patch (overlay)"
  fi
  return "$rc"
}

apply_php_types_iterable_generic_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if grep -qE "preg_match\('/\^\(list\|array\|iterable\)" "$target" 2>/dev/null; then
    echo "Skip php-types-iterable-generic.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = "        if (preg_match('/^(list|array)\\s*</i', trim($decl))) {\n            return new self(self::TYPE_ARRAY);\n        }\n"
new = "        if (preg_match('/^(list|array|iterable)\\s*</i', trim($decl))) {\n            return new self(self::TYPE_ARRAY);\n        }\n"
if old in text:
    path.write_text(text.replace(old, new, 1))
    raise SystemExit(0)
if "preg_match('/^(list|array|iterable)" in text:
    raise SystemExit(0)
needle = "        if (preg_match('/^(positive|negative|non-zero)-int$/', $pseudo)) {\n            return new self(self::TYPE_LONG);\n        }\n"
insert = needle + new
if needle in text:
    path.write_text(text.replace(needle, insert, 1))
    raise SystemExit(0)
sys.stderr.write("php-types-iterable-generic: list|array generic anchor not found\n")
raise SystemExit(1)
PY
  echo "Applied php-types-iterable-generic.patch (overlay)"
}

apply_php_types_array_shape_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if patch_already_applied "$PATCH_DIR/php-types-array-shape.patch"; then
    echo "Skip php-types-array-shape.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if "preg_match('/array\\{/i', $decl)" in text:
    print("Skip php-types-array-shape.patch (already applied)")
    raise SystemExit(0)
old = "        if (preg_match('/^array\\{/i', $decl)) {\n            return new self(self::TYPE_ARRAY);\n        }\n"
new = "        if (preg_match('/array\\{/i', $decl)) {\n            return new self(self::TYPE_ARRAY);\n        }\n"
if old in text:
    path.write_text(text.replace(old, new, 1))
    print("Applied php-types-array-shape.patch (overlay)")
    raise SystemExit(0)
needle = "        if (strpos($decl, '|') !== false || strpos($decl, '&') !== false || strpos($decl, '(') !== false) {\n"
insert = "        if (preg_match('/array\\{/i', $decl)) {\n            return new self(self::TYPE_ARRAY);\n        }\n" + needle
if needle in text:
    path.write_text(text.replace(needle, insert, 1))
    print("Applied php-types-array-shape.patch (overlay)")
    raise SystemExit(0)
sys.stderr.write("php-types-array-shape: anchor not found\n")
raise SystemExit(1)
PY
}

apply_php_types_anonymous_class_type_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if grep -q "@anonymous\\\\x00" "$target" 2>/dev/null; then
    echo "Skip php-types-anonymous-class-type.patch (already applied)"
    return 0
  fi
  if grep -q 'AnonymousClass@' "$target" 2>/dev/null; then
    python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = "        if (preg_match('/^AnonymousClass@\\d+$/', trim($decl))) {\n            return new self(self::TYPE_OBJECT, [], $decl);\n        }\n"
new = "        if (preg_match('/@anonymous\\x00/', $decl)) {\n            return new self(self::TYPE_OBJECT, [], $decl);\n        }\n" + old
if old not in text:
    sys.stderr.write("php-types-anonymous-class-type: upgrade anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
    echo "Applied php-types-anonymous-class-type.patch (overlay upgrade)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
needle = "        $regex = '(^([a-zA-Z_"
block = """        if (preg_match('/@anonymous\\x00/', $decl)) {
            return new self(self::TYPE_OBJECT, [], $decl);
        }
        if (preg_match('/^AnonymousClass@\\d+$/', trim($decl))) {
            return new self(self::TYPE_OBJECT, [], $decl);
        }
"""
idx = text.find(needle)
if idx < 0:
    sys.stderr.write("php-types-anonymous-class-type: Type.php anchor not found\n")
    raise SystemExit(1)
path.write_text(text[:idx] + block + text[idx:])
PY
  echo "Applied php-types-anonymous-class-type.patch (overlay)"
}

apply_php_types_ns_func_call_overlay() {
  apply_php_types_ns_func_call_overlay_to_target() {
    local target="$1"
    if grep -q 'function resolveOp_Expr_NsFuncCall' "$target" 2>/dev/null; then
      echo "Skip php-types-ns-func-call.patch (already applied): ${target}"
      return 0
    fi
    python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
ns_func_block = """    protected function resolveOp_Expr_NsFuncCall(Operand $var, Op\\Expr\\NsFuncCall $op, SplObjectStorage $resolved)
    {
        if ($op->nsName instanceof Operand\\Literal) {
            $name = strtolower($op->nsName->value);
            if (isset($this->state->functionLookup[$name])) {
                $result = [];
                foreach ($this->state->functionLookup[$name] as $func) {
                    if ($func->returnType) {
                        $result[] = Type::fromTypeDecl($func->returnType);
                    } else {
                        $result[] = Type::extractTypeFromComment('return', $func->getAttribute('doccomment'));
                    }
                }

                return $result;
            }
            if (isset($this->state->internalTypeInfo->functions[$name])) {
                $type = $this->state->internalTypeInfo->functions[$name];
                if (empty($type['return'])) {
                    return false;
                }

                return [Type::fromDecl($type['return'])];
            }
        }

        return false;
    }

"""
anchor = "    protected function resolveOp_Expr_New(Operand $var, Op\\Expr\\New_ $op, SplObjectStorage $resolved)"
if anchor not in text:
    sys.stderr.write("php-types-ns-func-call: resolveOp_Expr_New anchor not found\\n")
    raise SystemExit(1)
path.write_text(text.replace(anchor, ns_func_block + anchor, 1))
PY
    echo "Applied php-types-ns-func-call.patch (overlay): ${target}"
  }

  apply_php_types_ns_func_call_overlay_to_target "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  apply_php_types_ns_func_call_overlay_to_target "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
}

apply_php_types_arrow_function_overlay() {
  apply_php_types_arrow_function_overlay_to_target() {
    local target="$1"
    if [[ ! -f "$target" ]]; then
      return 0
    fi
    if grep -q 'function resolveOp_Expr_ArrowFunction' "$target" 2>/dev/null; then
      echo "Skip php-types-arrow-function.patch (already applied): ${target}"
      return 0
    fi
    python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
arrow_block = """    protected function resolveOp_Expr_ArrowFunction(Operand $var, Op\\Expr\\ArrowFunction $op, SplObjectStorage $resolved)
    {
        return [new Type(Type::TYPE_OBJECT, [], 'Closure')];
    }

"""
anchor = "    protected function resolveOp_Expr_FuncCall(Operand $var, Op\\Expr\\FuncCall $op, SplObjectStorage $resolved)"
closure_anchor = "    protected function resolveOp_Expr_Closure(Operand $var, Op\\Expr\\Closure $op, SplObjectStorage $resolved)"
if closure_anchor in text and anchor in text:
    needle = """    protected function resolveOp_Expr_Closure(Operand $var, Op\\Expr\\Closure $op, SplObjectStorage $resolved)
    {
        return [new Type(Type::TYPE_OBJECT, [], 'Closure')];
    }

"""
    if needle in text:
        path.write_text(text.replace(needle, needle + arrow_block, 1))
        raise SystemExit(0)
if anchor not in text:
    sys.stderr.write("php-types-arrow-function: resolveOp_Expr_FuncCall anchor not found\\n")
    raise SystemExit(1)
path.write_text(text.replace(anchor, arrow_block + anchor, 1))
PY
    echo "Applied php-types-arrow-function.patch (overlay): ${target}"
  }

  apply_php_types_arrow_function_overlay_to_target "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  apply_php_types_arrow_function_overlay_to_target "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
}

apply_php_types_fromdecl_junk_fragments_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if patch_already_applied "$PATCH_DIR/php-types-fromdecl-junk-fragments.patch"; then
    echo "Skip php-types-fromdecl-junk-fragments.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()

needle = "        $decl = self::stripTrailingDocText($decl);\n"
if needle not in text:
    sys.stderr.write("php-types-fromdecl-junk-fragments: stripTrailingDocText line not found\n")
    raise SystemExit(1)

junk = (
    "        // Malformed phpdoc fragments in vendor trees (north-star5 prelink; #2743, #2745).\n"
    "        if ('' === $trimmedDecl || '*' === $trimmedDecl || '*/' === $trimmedDecl\n"
    "            || str_starts_with($trimmedDecl, '*/')) {\n"
    "            return self::mixed();\n"
    "        }\n"
)

if "Malformed phpdoc fragments in vendor trees" not in text:
    if "$trimmedDecl = trim($decl);" not in text:
        insert = needle + "        $trimmedDecl = trim($decl);\n" + junk
        text = text.replace(needle, insert, 1)
    else:
        anchor = "        $trimmedDecl = trim($decl);\n"
        if anchor in text:
            text = text.replace(anchor, anchor + junk, 1)
    path.write_text(text)
PY
  echo "Applied php-types-fromdecl-junk-fragments.patch (overlay)"
}

apply_php_types_fromdecl_string_literals_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if patch_already_applied "$PATCH_DIR/php-types-fromdecl-string-literals.patch"; then
    echo "Skip php-types-fromdecl-string-literals.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if "Psalm/PHPStan string literal types" in text:
    raise SystemExit(0)

anchor = (
    "        // Malformed phpdoc fragments in vendor trees (north-star5 prelink; #2743, #2745).\n"
    "        if ('' === $trimmedDecl || '*' === $trimmedDecl || '*/' === $trimmedDecl\n"
    "            || str_starts_with($trimmedDecl, '*/')) {\n"
    "            return self::mixed();\n"
    "        }\n"
)
if anchor not in text:
    sys.stderr.write("php-types-fromdecl-string-literals: junk-fragments anchor not found\n")
    raise SystemExit(1)

insert = (
    anchor
    + "        // Psalm/PHPStan string literal types: 'parent' / \"foo\" (#26686).\n"
    + "        // Spine docblocks use @return null|'parent'|'self'|'static' (#26630 / #26655).\n"
    + "        if (\n"
    + "            (strlen($trimmedDecl) >= 2 && \"'\" === $trimmedDecl[0] && str_ends_with($trimmedDecl, \"'\"))\n"
    + "            || (strlen($trimmedDecl) >= 2 && '\"' === $trimmedDecl[0] && str_ends_with($trimmedDecl, '\"'))\n"
    + "        ) {\n"
    + "            return new self(self::TYPE_STRING);\n"
    + "        }\n"
)
path.write_text(text.replace(anchor, insert, 1))
PY
  echo "Applied php-types-fromdecl-string-literals.patch (overlay)"
}

