#!/usr/bin/env bash
# Apply local patches to vendored dependencies (php-llvm PHP 8.2 / LLVM path fixes).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PATCH_DIR="$ROOT/patches"
VENDOR_LLVM="$ROOT/vendor/ircmaxell/php-llvm"
APPLY_PATCH_FAILURES=()

if [[ ! -d "$VENDOR_LLVM" ]]; then
  echo "vendor/ircmaxell/php-llvm not found; run composer install first" >&2
  exit 1
fi

# Copy an overlay file only when bytes differ (#36229 honesty: never claim Applied on a no-op cp).
install_overlay_file() {
  local dest="$1"
  local overlay="$2"
  local label="$3"
  if [[ ! -f "$overlay" ]]; then
    echo "Skip ${label} (missing $overlay)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$dest")"
  if [[ -f "$dest" ]] && cmp -s "$overlay" "$dest"; then
    echo "Skip ${label} (already applied)"
    return 0
  fi
  cp "$overlay" "$dest"
  echo "Applied ${label}"
}

patch_already_applied() {
  local patch="$1"
  # shellcheck source=script/lib/patch-already-applied.inc.sh
  source "$ROOT/script/lib/patch-already-applied.inc.sh"
}

apply_php_llvm_memory_buffer_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/MemoryBuffer.php"
  local overlay="$PATCH_DIR/overlays/php-llvm/MemoryBuffer.php"
  if patch_already_applied "$PATCH_DIR/php-llvm-memory-buffer-bitcode.patch"; then
    echo "Skip php-llvm-memory-buffer-bitcode.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay" ]]; then
    echo "Skip php-llvm-memory-buffer-bitcode.patch (overlay missing)" >&2
    return 1
  fi
  cp "$overlay" "$target"
  echo "Applied php-llvm-memory-buffer-bitcode.patch (overlay)"
}

# Overlay libraries extracted for size ratchet (#36403).
# Mid php-cfg (+ halt-offset / final-property) overlays live in apply-php-cfg-overlays-mid.inc.sh.
# shellcheck source=script/lib/apply-php-cfg-overlays-early.inc.sh
source "$ROOT/script/lib/apply-php-cfg-overlays-early.inc.sh"
# shellcheck source=script/lib/apply-php-types-overlays.inc.sh
source "$ROOT/script/lib/apply-php-types-overlays.inc.sh"

# shellcheck source=script/lib/apply-php-cfg-overlays-mid.inc.sh
source "$ROOT/script/lib/apply-php-cfg-overlays-mid.inc.sh"


apply_php_llvm_no_closures_array_map_overlay() {
  local context="$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Context.php"
  local struct="$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Type/Struct.php"
  if patch_already_applied "$PATCH_DIR/php-llvm-no-closures-array-map.patch"; then
    echo "Skip php-llvm-no-closures-array-map.patch (already applied)"
    return 0
  fi
  python3 - "$context" "$struct" <<'PY'
import sys
from pathlib import Path

context_path = Path(sys.argv[1])
struct_path = Path(sys.argv[2])

ARRAY_MAP_IN_MAKE_ARRAY = """            array_map(
                function(Type $type) {
                    return $type->type;
                },
                {iterable}
            )"""

new_function_type = """    public function functionType(CoreType $returnType, bool $isVarArgs, CoreType ... $parameters): CoreFunctionType {
        $paramWrapper = null;
        if (count($parameters) > 0) {
            $paramTypes = [];
            foreach ($parameters as $type) {
                $paramTypes[] = $type->type;
            }
            $paramWrapper = $this->llvm->lib->makeArray(
                LLVMTypeRef_ptr::class,
                $paramTypes
            );
        }
        return $this->llvm->factory->type(
            $this, 
            $this->llvm->lib->LLVMFunctionType(
                $returnType->type,
                $paramWrapper,
                count($parameters),
                // LLVM is stupid, and even though the type is declared LLVMBool, it's not, and is a normal "1/0" bool instead of the weird reversed...
                $isVarArgs ? 1 : 0
            )
        );
    }"""

new_struct_type = """    public function structType(bool $packed, CoreType ... $elements): CoreType {
        $elementWrapper = null;
        if (count($elements) > 0) {
            $elementTypes = [];
            foreach ($elements as $type) {
                $elementTypes[] = $type->type;
            }
            $elementWrapper = $this->llvm->lib->makeArray(
                LLVMTypeRef_ptr::class,
                $elementTypes
            );
        }
        return $this->llvm->factory->type(
            $this,
            $this->llvm->lib->LLVMStructTypeInContext(
                $this->context,
                $elementWrapper,
                count($elements),
                $this->llvm->toBool($packed)
            )
        );
    }"""

new_set_body = """    public function setBody(bool $packed, CoreType ... $elements): void {
        $elementTypes = [];
        foreach ($elements as $type) {
            $elementTypes[] = $type->type;
        }
        $elementWrapper = $this->llvm->lib->makeArray(
            LLVMTypeRef_ptr::class,
            $elementTypes
        );
        $this->llvm->lib->LLVMStructSetBody(
            $this->type,
            $elementWrapper,
            count($elements),
            $this->llvm->toBool($packed)
        );
    }"""


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        return text
    return text.replace(old, new, 1)


def replace_array_map_in_make_array(text: str, iterable: str, types_var: str) -> str:
    old = ARRAY_MAP_IN_MAKE_ARRAY.format(iterable=iterable)
    if old not in text:
        old = old.replace(",\n                ", ", \n                ")
    if old not in text:
        return text
    new = f"""            ${types_var} = [];
            foreach ({iterable} as $type) {{
                ${types_var}[] = $type->type;
            }}"""
    return text.replace(old, new, 1)


def replace_function_type(context: str) -> str:
    if "foreach ($parameters as $type)" in context:
        return context
    anchors = [
        """    public function functionType(CoreType $returnType, bool $isVarArgs, CoreType ... $parameters): CoreFunctionType {
        $paramWrapper = $this->llvm->lib->makeArray(
            LLVMTypeRef_ptr::class,
            array_map(
                function(Type $type) {
                    return $type->type;
                }, 
                $parameters
            )
        );
        return $this->llvm->factory->type(
            $this, 
            $this->llvm->lib->LLVMFunctionType(
                $returnType->type,
                $paramWrapper,
                count($parameters),
                // LLVM is stupid, and even though the type is declared LLVMBool, it's not, and is a normal "1/0" bool instead of the weird reversed...
                $isVarArgs ? 1 : 0
            )
        );
    }""",
        """    public function functionType(CoreType $returnType, bool $isVarArgs, CoreType ... $parameters): CoreFunctionType {
        $paramWrapper = null;
        if (count($parameters) > 0) {
            $paramWrapper = $this->llvm->lib->makeArray(
                LLVMTypeRef_ptr::class,
                array_map(
                    function(Type $type) {
                        return $type->type;
                    },
                    $parameters
                )
            );
        }
        return $this->llvm->factory->type(
            $this, 
            $this->llvm->lib->LLVMFunctionType(
                $returnType->type,
                $paramWrapper,
                count($parameters),
                // LLVM is stupid, and even though the type is declared LLVMBool, it's not, and is a normal "1/0" bool instead of the weird reversed...
                $isVarArgs ? 1 : 0
            )
        );
    }""",
    ]
    for old in anchors:
        if old in context:
            return replace_once(context, old, new_function_type, "functionType")
    updated = replace_array_map_in_make_array(context, "$parameters", "paramTypes")
    if updated != context:
        return updated
    sys.stderr.write("php-llvm-no-closures-array-map: expected Context.php functionType anchor not found\n")
    sys.exit(1)


def replace_struct_type(context: str) -> str:
    if "foreach ($elements as $type)" in context and "public function structType" in context:
        before = context.split("public function structType", 1)[1]
        if "array_map(" not in before.split("public function", 1)[0]:
            return context
    anchors = [
        """    public function structType(bool $packed, CoreType ... $elements): CoreType {
        $elementWrapper = $this->llvm->lib->makeArray(
            LLVMTypeRef_ptr::class,
            array_map(
                function(Type $type) {
                    return $type->type;
                }, 
                $elements
            )
        );
        return $this->llvm->factory->type(
            $this,
            $this->llvm->lib->LLVMStructTypeInContext(
                $this->context,
                $elementWrapper,
                count($elements),
                $this->llvm->toBool($packed)
            )
        );
    }""",
        """    public function structType(bool $packed, CoreType ... $elements): CoreType {
        $elementWrapper = null;
        if (count($elements) > 0) {
            $elementWrapper = $this->llvm->lib->makeArray(
                LLVMTypeRef_ptr::class,
                array_map(
                    function(Type $type) {
                        return $type->type;
                    },
                    $elements
                )
            );
        }
        return $this->llvm->factory->type(
            $this,
            $this->llvm->lib->LLVMStructTypeInContext(
                $this->context,
                $elementWrapper,
                count($elements),
                $this->llvm->toBool($packed)
            )
        );
    }""",
    ]
    for old in anchors:
        if old in context:
            return replace_once(context, old, new_struct_type, "structType")
    updated = replace_array_map_in_make_array(context, "$elements", "elementTypes")
    if updated != context:
        return updated
    sys.stderr.write("php-llvm-no-closures-array-map: expected Context.php structType anchor not found\n")
    sys.exit(1)


context = context_path.read_text()
context = replace_function_type(context)
context = replace_struct_type(context)
context_path.write_text(context)

struct = struct_path.read_text()
if "foreach ($elements as $type)" not in struct:
    old_set_body = """    public function setBody(bool $packed, CoreType ... $elements): void {
        $elementWrapper = $this->llvm->lib->makeArray(
            LLVMTypeRef_ptr::class,
            array_map(
                function(Type $type) {
                    return $type->type;
                }, 
                $elements
            )
        );
        $this->llvm->lib->LLVMStructSetBody(
            $this->type,
            $elementWrapper,
            count($elements),
            $this->llvm->toBool($packed)
        );
    }"""
    if old_set_body not in struct:
        updated = replace_array_map_in_make_array(struct, "$elements", "elementTypes")
        if updated == struct:
            sys.stderr.write("php-llvm-no-closures-array-map: expected Struct.php anchor not found\n")
            sys.exit(1)
        struct = updated
    else:
        struct = struct.replace(old_set_body, new_set_body, 1)
struct_path.write_text(struct)
PY
  echo "Applied php-llvm-no-closures-array-map.patch (overlay)"
}

# PHP 8.4 implicitly-nullable params in php-cfg / php-optimizer — surgical overlay.
# The committed .patch hunks go stale whenever php-cfg overlays rewrite the same
# signatures (Func blank-line drift, Property lazy fields, Parser ctor context).
# Prefer this overlay over git apply so apply-patches stays green after overlays (#25042).
apply_php_vendor_implicit_nullable_84_overlay() {
  if patch_already_applied "$PATCH_DIR/php-vendor-implicit-nullable-84.patch"; then
    echo "Skip php-vendor-implicit-nullable-84.patch (already applied)"
    return 0
  fi
  python3 - "$ROOT" <<'PY'
import re
import sys
from pathlib import Path

root = Path(sys.argv[1])
files = [
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/AbstractVisitor.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Block.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Func.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/ArrayDimFetch.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/ClassConstFetch.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/ConstFetch.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Exit_.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Yield_.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Class_.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Return_.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/StaticVar.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Operand/Temporary.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Printer.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Traverser.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Visitor/DeadBlockEliminator.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Visitor/DebugVisitor.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Visitor/PhiResolver.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Visitor/VariableFinder.php",
    "vendor/ircmaxell/php-cfg/lib/PHPCfg/Visitor.php",
    "vendor/ircmaxell/php-optimizer/lib/PHPOptimizer/Visitor/JumpBlockEliminator.php",
]
param_typed = re.compile(
    r"(?<![?\w\\\\])((?:\\\\)?(?:self|static|parent|[A-Za-z_][\w\\\\]*))\s+(\$[A-Za-z_][\w]*)\s*=\s*null\b"
)

def transform(text: str) -> str:
    """Rewrite Type $x = null → ?Type $x = null inside function parameter lists only."""
    out = []
    i = 0
    n = len(text)
    while i < n:
        m = re.search(r"\bfunction\b", text[i:])
        if not m:
            out.append(text[i:])
            break
        start = i + m.start()
        out.append(text[i:start])
        paren = text.find("(", start)
        if paren < 0:
            out.append(text[start : start + 8])
            i = start + 8
            continue
        depth = 0
        j = paren
        while j < n:
            c = text[j]
            if c == "(":
                depth += 1
            elif c == ")":
                depth -= 1
                if depth == 0:
                    break
            j += 1
        else:
            out.append(text[start:])
            break
        params = text[paren + 1 : j]
        new_params = param_typed.sub(
            lambda mm: f"?{mm.group(1)} {mm.group(2)} = null", params
        )
        out.append(text[start : paren + 1] + new_params + ")")
        i = j + 1
    return "".join(out)

changed = 0
for rel in files:
    path = root / rel
    if not path.is_file():
        continue
    old = path.read_text()
    new = transform(old)
    if "?public" in new or "?protected" in new or "?private" in new:
        sys.stderr.write(f"php-vendor-implicit-nullable-84: refused property rewrite in {rel}\n")
        raise SystemExit(1)
    if new != old:
        path.write_text(new)
        changed += 1

marker = root / "vendor/ircmaxell/php-cfg/lib/PHPCfg/AbstractVisitor.php"
if not marker.is_file() or "?Block $prior = null" not in marker.read_text():
    sys.stderr.write(
        "php-vendor-implicit-nullable-84: AbstractVisitor ?Block $prior marker missing after overlay\n"
    )
    raise SystemExit(1)
print(f"nullable-84 overlay touched {changed} file(s)", file=sys.stderr)
PY
  echo "Applied php-vendor-implicit-nullable-84.patch (overlay)"
}

record_patch_failure() {
  local patch_name="$1"
  local detail="${2:-}"
  APPLY_PATCH_FAILURES+=("$patch_name")
  echo "ERROR: failed to apply ${patch_name}" >&2
  if [[ -n "$detail" ]]; then
    echo "  ${detail}" >&2
  fi
  echo "  Hint: git -C \"${ROOT}\" apply --check -p0 \"${PATCH_DIR}/${patch_name}\"" >&2
}

# Zero-fuzz patch(1): never silently apply with drifted context (#36229).
# Callers pass the same flags as before (e.g. -p0 -s --dry-run); -F0 is injected.
run_patch() {
  patch -F0 "$@"
}

# Hunkless .patch stubs are forbidden — overlays must be called by name, not via empty files (#36229).
reject_hunkless_patches() {
  local f bad=0
  shopt -s nullglob
  for f in "$PATCH_DIR"/*.patch; do
    if ! grep -qE '^@@' "$f"; then
      echo "apply-patches: hunkless stub forbidden: $(basename "$f") (#36229 — delete stub; call overlay by name)" >&2
      bad=1
    fi
  done
  shopt -u nullglob
  return "$bad"
}

# When git apply / patch(1) cannot run (stale hunk lines, corrupt diff), detect prior apply
# from the first added line or new-file target in the patch file.
patch_marker_present() {
  local patch="$1"
  local old_path new_path marker
  old_path="$(grep -m1 '^--- ' "$patch" | awk '{print $2}')"
  new_path="$(grep -m1 '^\+\+\+ ' "$patch" | awk '{print $2}')"
  old_path="${old_path#a/}"
  new_path="${new_path#b/}"
  if [[ "$old_path" == "/dev/null" ]]; then
    [[ -n "$new_path" && -f "$ROOT/$new_path" ]]
    return
  fi
  marker="$(grep -m1 '^+[^+]' "$patch" | sed 's/^+//' || true)"
  if [[ -z "$marker" || -z "$old_path" ]]; then
    return 1
  fi
  grep -qF "$marker" "$ROOT/$old_path" 2>/dev/null
}

repair_php_llvm_token_type_kind_typo_in_prelinked() {
  local target="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-llvm/lib/LLVMAbstract/Type.php"
  if [[ ! -f "$target" ]]; then
    return 0
  fi
  if grep -q 'LLVMTokenTypeKin' "$target" 2>/dev/null; then
    sed -i 's/lib::LLVMTokenTypeKin:/lib::LLVMTokenTypeKind:/' "$target"
    echo "Repaired php-llvm LLVMTokenTypeKin typo in prelinked bootstrap-vendor (#11396)"
  fi
}

# Harness runs sometimes rename the assert env var to REMOVED_TEST to probe removal;
# that leaves vendor half-patched and structgep skip falsely fails (grep substring match).
repair_php_llvm_assert_env_var() {
  local target="$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Builder.php"
  if [[ ! -f "$target" ]]; then
    return 0
  fi
  if grep -q "PHP_COMPILER_LLVM_ASSERT_REMOVED_TEST" "$target" 2>/dev/null; then
    sed -i "s/PHP_COMPILER_LLVM_ASSERT_REMOVED_TEST/PHP_COMPILER_LLVM_ASSERT/g" "$target"
    echo "Repaired php-llvm Builder.php (PHP_COMPILER_LLVM_ASSERT_REMOVED_TEST → PHP_COMPILER_LLVM_ASSERT) (#36143)"
  fi
}

apply_php_types_static_var_array_type_repair() {
  # Old php-types-static-var.patch peeled ->subTypes, typing string[] statics as string (#32806).
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  if [[ ! -f "$target" ]]; then
    return 0
  fi
  if ! grep -q "case 'Terminal_StaticVar':" "$target" 2>/dev/null; then
    return 0
  fi
  if ! grep -q 'defaultVar]->subTypes ??' "$target" 2>/dev/null; then
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
path = sys.argv[1]
text = open(path, encoding='utf-8').read()
old = "return $resolved[$op->defaultVar]->subTypes ?? [$resolved[$op->defaultVar]];"
new = "return [$resolved[$op->defaultVar]];"
if old not in text:
    sys.exit(0)
# Keep a short comment near the case for the next reader.
text = text.replace(
    "case 'Terminal_StaticVar':\n                if (null !== $op->defaultVar) {",
    "case 'Terminal_StaticVar':\n                // Keep the default's full type (string[] stays array). Using ->subTypes here\n"
    "                // typed `static $a=['x']` as string and broke AOT dim writes (#32800 / #32806).\n"
    "                if (null !== $op->defaultVar) {",
    1,
)
text = text.replace(old, new, 1)
open(path, 'w', encoding='utf-8').write(text)
print('Repaired php-types-static-var Terminal_StaticVar array type (#32806)')
PY
}

# True when the patch is genuinely present (reverse-apply succeeds), not just a grep marker.
patch_is_fully_applied() {
  local patch="$1"
  if git -C "$ROOT" apply --check -p0 -R "$patch" >/dev/null 2>&1; then
    return 0
  fi
  if git -C "$ROOT" apply --check -p1 -R "$patch" >/dev/null 2>&1; then
    return 0
  fi
  if command -v patch >/dev/null 2>&1; then
    # -F0: refuse fuzz; no -f: refuse partial reverse as "already applied" (#36229).
    if run_patch -p0 --reverse --dry-run -s < "$patch" >/dev/null 2>&1; then
      return 0
    fi
    if run_patch -p1 --reverse --dry-run -s < "$patch" >/dev/null 2>&1; then
      return 0
    fi
  fi
  return 1
}

# Verify a single patch applies to a pristine snapshot (vendor *.orig or patches/pristine/).
verify_pristine_patch_on_orig() {
  local patch="$1" orig_rel="$2"
  local orig="$ROOT/$orig_rel" dest_rel scratch
  if [[ ! -f "$patch" ]]; then
    return 0
  fi
  if [[ ! -f "$orig" ]]; then
    local snap_rel="${orig_rel#vendor/}"
    snap_rel="${snap_rel%.orig}"
    orig="$ROOT/patches/pristine-snapshots/$snap_rel"
  fi
  if [[ ! -f "$orig" ]]; then
    echo "verify-pristine: skip $(basename "$patch") (no pristine snapshot for ${orig_rel})"
    return 0
  fi
  dest_rel="${orig_rel%.orig}"
  dest_rel="${dest_rel#vendor/}"
  scratch="$(mktemp -d "${TMPDIR:-/tmp}/phpc-pristine-one.XXXXXX")"
  mkdir -p "$scratch/vendor/$(dirname "$dest_rel")"
  cp "$orig" "$scratch/vendor/$dest_rel"
  if (cd "$scratch" && git apply --check -p0 "$patch") >/dev/null 2>&1; then
    rm -rf "$scratch"
    echo "verify-pristine: OK   $(basename "$patch")"
    return 0
  fi
  if (cd "$scratch" && git apply --check -p1 "$patch") >/dev/null 2>&1; then
    rm -rf "$scratch"
    echo "verify-pristine: OK   $(basename "$patch") (-p1)"
    return 0
  fi
  rm -rf "$scratch"
  echo "verify-pristine: FAIL $(basename "$patch") (does not apply to pristine ${orig_rel})" >&2
  return 1
}

# Ordered php-llvm patches that apply_patch actually runs (keep in sync with the
# apply_patch "$PATCH_DIR/php-llvm-…" list later in this file).
list_applied_php_llvm_patches() {
  grep -E '^apply_patch "\$PATCH_DIR/php-llvm-' "$ROOT/script/apply-patches.sh" \
    | sed 's/.*\/\(php-llvm-[^"]*\)".*/\1/'
}

# Patches reachable from apply_patch / apply_patch_file_direct (source of truth).
# On-disk *.patch files not in this set are orphans — deleted stubs left behind after
# overlays took over, or never wired into the apply list (#36229).
# Scan sourced overlay libs too — mid/early extracts move apply_patch_file_direct
# call sites out of this driver (#36403).
list_reachable_patch_names() {
  {
    grep -E 'apply_patch(_file_direct)? "\$PATCH_DIR/[^"]+\.patch"' \
      "$ROOT/script/apply-patches.sh" \
      "$ROOT/script/lib"/apply-php-*-overlays*.inc.sh \
      2>/dev/null \
      | sed -E 's/.*\/([^"/]+\.patch)".*/\1/'
  } | sort -u
}

# Fail if patches/*.patch is not referenced by apply_patch / apply_patch_file_direct.
verify_no_orphan_patches() {
  local orphans
  orphans="$(comm -23 <(cd "$PATCH_DIR" && ls -1 *.patch | sort) <(list_reachable_patch_names))"
  if [[ -n "$orphans" ]]; then
    echo "verify-pristine: on-disk patches not reachable from apply_patch/apply_patch_file_direct (#36229):" >&2
    echo "$orphans" >&2
    return 1
  fi
  echo "verify-pristine: no orphan patches (every on-disk *.patch is in the apply list)"
  return 0
}

# Fast gate: every on-disk php-llvm-*.patch is in the apply list, and the whole
# stack applies in order to the committed unpatched snapshot (#36229 / re-#36143).
# Isolated per-patch checks against Builder.php.orig only covered 2/28 files and
# skipped missing snapshots — the structgep recurrence.
verify_pristine_patches() {
  local failed=0
  local snap="$ROOT/patches/pristine-snapshots/ircmaxell/php-llvm"
  local scratch name patch applied listed extra
  reject_hunkless_patches || failed=1
  verify_no_orphan_patches || failed=1
  if [[ ! -f "$snap/ORIGIN" || ! -d "$snap/lib" ]]; then
    echo "verify-pristine: missing php-llvm snapshot at ${snap} (ORIGIN+lib/) (#36229)" >&2
    return 1
  fi
  listed="$(list_applied_php_llvm_patches | sort)"
  extra="$(comm -23 <(cd "$PATCH_DIR" && ls -1 php-llvm-*.patch | sort) <(printf '%s\n' "$listed"))"
  if [[ -n "$extra" ]]; then
    echo "verify-pristine: php-llvm patches on disk not in apply_patch list (#36229):" >&2
    echo "$extra" >&2
    failed=1
  fi
  if [[ ! -d "$snap/ffi" ]]; then
    echo "verify-pristine: php-llvm snapshot missing ffi/ (makearray patch) (#36229)" >&2
    failed=1
  fi
  if [[ "$failed" -ne 0 ]]; then
    echo "verify-pristine: patch drift detected — re-diff against pristine vendor (#36209/#36229)" >&2
    return 1
  fi
  scratch="$(mktemp -d "${TMPDIR:-/tmp}/phpc-pristine-llvm-stack.XXXXXX")"
  mkdir -p "$scratch/vendor/ircmaxell/php-llvm"
  cp -a "$snap/lib" "$scratch/vendor/ircmaxell/php-llvm/lib"
  cp -a "$snap/ffi" "$scratch/vendor/ircmaxell/php-llvm/ffi"
  applied=0
  while IFS= read -r name; do
    [[ -z "$name" ]] && continue
    patch="$PATCH_DIR/$name"
    if ! git -C "$scratch" apply -p0 "$patch" >/dev/null 2>&1; then
      echo "verify-pristine: FAIL $name (does not apply in php-llvm stack order to snapshot $(grep -v '^#' "$snap/ORIGIN" | head -n1))" >&2
      git -C "$scratch" apply --check -p0 "$patch" >&2 || true
      rm -rf "$scratch"
      echo "verify-pristine: patch drift detected — re-diff against pristine vendor (#36209/#36229)" >&2
      return 1
    fi
    echo "verify-pristine: OK   $name (stack)"
    applied=$((applied + 1))
  done < <(list_applied_php_llvm_patches)
  rm -rf "$scratch"
  if [[ "$applied" -lt 1 ]]; then
    echo "verify-pristine: FAIL empty php-llvm apply list" >&2
    return 1
  fi
  echo "verify-pristine: llvm patches apply to pristine vendor snapshots; no hunkless stubs"
  echo "verify-pristine: php-llvm stack ${applied}/$(ls -1 "$PATCH_DIR"/php-llvm-*.patch | wc -l) applied in order to snapshot $(grep -v '^#' "$snap/ORIGIN" | head -n1)"
  return 0
}

# Apply a patch file with git/patch(1) only — no overlay dispatch (avoids recursion).
# patch(1) path uses -F0 and refuses partial applies (#36229).
apply_patch_file_direct() {
  local patch="$1"
  local patch_name
  local patch_rc
  patch_name="$(basename "$patch")"
  if [[ ! -f "$patch" ]]; then
    return 0
  fi
  if patch_already_applied "$patch"; then
    echo "Skip ${patch_name} (already applied)"
    return 0
  fi
  if git -C "$ROOT" apply --check -p0 "$patch" >/dev/null 2>&1; then
    git -C "$ROOT" apply -p0 "$patch"
    echo "Applied ${patch_name}"
    return 0
  fi
  if git -C "$ROOT" apply --check -p1 "$patch" >/dev/null 2>&1; then
    git -C "$ROOT" apply -p1 "$patch"
    echo "Applied ${patch_name} (-p1)"
    return 0
  fi
  if command -v patch >/dev/null 2>&1; then
    if run_patch -p0 --dry-run -s < "$patch" >/dev/null 2>&1; then
      set +e
      run_patch -p0 -s < "$patch" >/dev/null 2>&1
      patch_rc=$?
      set -e
      if [[ "$patch_rc" -eq 0 ]]; then
        echo "Applied ${patch_name} (patch(1) -F0)"
        return 0
      fi
      record_patch_failure "${patch_name}" "patch(1) -p0 partial or failed (rc=${patch_rc}; -F0)"
      return 1
    fi
    if run_patch -p1 --dry-run -s < "$patch" >/dev/null 2>&1; then
      set +e
      run_patch -p1 -s < "$patch" >/dev/null 2>&1
      patch_rc=$?
      set -e
      if [[ "$patch_rc" -eq 0 ]]; then
        echo "Applied ${patch_name} (patch(1) -F0, -p1)"
        return 0
      fi
      record_patch_failure "${patch_name}" "patch(1) -p1 partial or failed (rc=${patch_rc}; -F0)"
      return 1
    fi
    if run_patch -p0 --reverse --dry-run -s < "$patch" >/dev/null 2>&1; then
      echo "Skip ${patch_name} (already applied)"
      return 0
    fi
    if run_patch -p1 --reverse --dry-run -s < "$patch" >/dev/null 2>&1; then
      echo "Skip ${patch_name} (already applied, -p1)"
      return 0
    fi
  fi
  if patch_marker_present "$patch"; then
    echo "Skip ${patch_name} (already applied)"
    return 0
  fi
  record_patch_failure "${patch_name}"
  return 1
}

apply_php_cfg_class_optional_param_order_overlay() {
  if patch_already_applied "$PATCH_DIR/php-cfg-class-optional-param-order.patch"; then
    echo "Skip php-cfg-class-optional-param-order.patch (already applied)"
    return 0
  fi
  local class_file="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Class_.php"
  local parser_file="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  # Fix Class_.php constructor: move optional $extends after required params
  if grep -q '?Operand \$extends = null, array \$implements' "$class_file" 2>/dev/null; then
    sed -i 's/?Operand $extends = null, array $implements, Block $stmts/array $implements, Block $stmts, ?Operand $extends = null/' "$class_file"
  fi
  # Fix Parser.php call site: reorder args to match new signature
  if grep -q 'parseExprNode($node->extends),' "$parser_file" 2>/dev/null; then
    python3 - "$parser_file" <<'PY'
import sys
from pathlib import Path
p = Path(sys.argv[1])
text = p.read_text()
old = """        $this->block->children[] = new Op\\Stmt\\Class_(
            $name,
            $node->flags,
            $this->parseExprNode($node->extends),
            $this->parseExprList($node->implements),
            $this->parseNodes($node->stmts, new Block()),
            $this->mapAttributes($node)
        );"""
new = """        $this->block->children[] = new Op\\Stmt\\Class_(
            $name,
            $node->flags,
            $this->parseExprList($node->implements),
            $this->parseNodes($node->stmts, new Block()),
            $this->parseExprNode($node->extends),
            $this->mapAttributes($node)
        );"""
if old in text:
    p.write_text(text.replace(old, new, 1))
    print('Applied php-cfg-class-optional-param-order.patch (overlay)')
elif 'parseExprList($node->implements),' in text and 'parseExprNode($node->extends)' in text:
    print('Skip php-cfg-class-optional-param-order.patch (already applied)')
else:
    sys.stderr.write('php-cfg-class-optional-param-order: Parser anchor not found\n')
    raise SystemExit(1)
PY
  else
    echo "Skip php-cfg-class-optional-param-order.patch (already applied)"
  fi
  return 0
}

apply_patch() {
  local patch="$1"
  local patch_name
  patch_name="$(basename "$patch")"
  # Overlay-only names (deleted hunkless stubs #36229) dispatch before the file check.
  # Missing optional/real patches fall through to apply_patch_file_direct (returns 0).
  if [[ "$patch_name" == "php-cfg-new-ctor-parens.patch" ]]; then
    # Optional, known-stale diff (#6549). Keep it non-fatal (do not record failure).
    echo "Skip ${patch_name} (optional — stale hunk #6549; does not block throw-expr #6746)"
    return 0
  fi
  if [[ "$(basename "$patch")" == "php-cfg-incdec-expr.patch" ]]; then
    apply_php_cfg_incdec_expr_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-vendor-implicit-nullable-84.patch" ]]; then
    # Hunks go stale after php-cfg overlays rewrite the same signatures (#25042).
    apply_php_vendor_implicit_nullable_84_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-class-optional-param-order.patch" ]]; then
    apply_php_cfg_class_optional_param_order_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-loop-resolver-continue-switch-warning.patch" ]]; then
    if apply_php_cfg_loop_resolver_continue_switch_warning_overlay; then
      return 0
    fi
  fi
  if [[ "$(basename "$patch")" == "php-cfg-loop-resolver-break-continue-positive.patch" ]]; then
    if apply_php_cfg_loop_resolver_break_continue_positive_overlay; then
      return 0
    fi
  fi
  if [[ "$(basename "$patch")" == "php-cfg-yield-keyed.patch" ]]; then
    apply_php_cfg_yield_keyed_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-magic-constants.patch" ]]; then
    apply_php_cfg_magic_constants_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-magic-script-const.patch" ]]; then
    apply_php_cfg_magic_script_const_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-declare-ticks.patch" ]]; then
    apply_php_cfg_declare_ticks_overlay
    apply_php_cfg_for_loop_increment_ticks_overlay
    apply_php_cfg_loop_exit_tick_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-enum.patch" ]]; then
    apply_php_cfg_enum_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-enum-implements.patch" ]]; then
    apply_php_cfg_enum_implements_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-enum-abstract.patch" ]]; then
    apply_php_cfg_enum_abstract_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-enum-class-const.patch" ]]; then
    apply_php_cfg_enum_class_const_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-enum-trait-use.patch" ]]; then
    apply_php_cfg_enum_trait_use_parser_fix
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-intersection-type.patch" ]]; then
    apply_php_cfg_intersection_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-instanceof-union.patch" ]]; then
    apply_php_cfg_instanceof_union_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-attribute-groups.patch" ]]; then
    apply_php_cfg_attribute_groups_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-str-bool-fns.patch" ]]; then
    apply_php_types_str_bool_fns_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-docblock-first-token.patch" ]]; then
    apply_php_types_docblock_full_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-iterable-generic.patch" ]]; then
    apply_php_types_iterable_generic_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-array-shape.patch" ]]; then
    apply_php_types_array_shape_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-generics-fallback.patch" ]]; then
    local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
    if grep -q "non-empty-string" "$target" 2>/dev/null; then
      echo "Skip php-types-generics-fallback.patch (already applied)"
      return 0
    fi
    if [[ -f "$PATCH_DIR/php-types-generics-fallback.patch" ]] \
      && run_patch -p0 --forward --dry-run < "$PATCH_DIR/php-types-generics-fallback.patch" >/dev/null 2>&1; then
      if ! run_patch -p0 --forward < "$PATCH_DIR/php-types-generics-fallback.patch"; then
        record_patch_failure "php-types-generics-fallback.patch" "patch(1) -F0 partial or failed"
        return 1
      fi
      echo "Applied php-types-generics-fallback.patch"
      return 0
    fi
    python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()

anchors = [
    """        if (preg_match('/^(list|array)\\s*</i', trim($decl))) {
            return new self(self::TYPE_ARRAY);
        }
        $regex = """,
    """        if (substr($decl, -2) === '[]') {
            $type = self::fromDecl(substr($decl, 0, -2));

            return new self(self::TYPE_ARRAY, [$type]);
        }
        $regex = """,
]
insert = """        if (preg_match('/^(list|array)\\s*</i', trim($decl))) {
            return new self(self::TYPE_ARRAY);
        }
        $pseudo = strtolower(trim($decl));
        if (in_array($pseudo, [
            'non-empty-string', 'literal-string', 'lowercase-string', 'uppercase-string',
            'class-string', 'interface-string', 'trait-string', 'html-escaped-string',
        ], true)) {
            return new self(self::TYPE_STRING);
        }
        if (in_array($pseudo, ['non-empty-array'], true)) {
            return new self(self::TYPE_ARRAY);
        }
        if (preg_match('/^(positive|negative|non-zero)-int$/', $pseudo)) {
            return new self(self::TYPE_LONG);
        }
        $regex = """

for anchor in anchors:
    if anchor in text:
        path.write_text(text.replace(anchor, insert, 1))
        break
else:
    sys.stderr.write("php-types-generics-fallback: anchor not found in Type.php\n")
    sys.exit(1)
PY
    echo "Applied php-types-generics-fallback.patch (overlay)"
    return 0
  fi
  if [[ "$(basename "$patch")" == "php-types-class-generics-fallback.patch" ]]; then
    local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
    if grep -q "Class generics from PHPStan/Psalm docblocks: App<T>" "$target" 2>/dev/null; then
      echo "Skip php-types-class-generics-fallback.patch (already applied)"
      return 0
    fi
    if [[ -f "$PATCH_DIR/php-types-class-generics-fallback.patch" ]] \
      && run_patch -p0 --forward --dry-run < "$PATCH_DIR/php-types-class-generics-fallback.patch" >/dev/null 2>&1; then
      if ! run_patch -p0 --forward < "$PATCH_DIR/php-types-class-generics-fallback.patch"; then
        record_patch_failure "php-types-class-generics-fallback.patch" "patch(1) -F0 partial or failed"
        return 1
      fi
      echo "Applied php-types-class-generics-fallback.patch"
      return 0
    fi
    # Warm end-state after generics-fallback + iterable-generic + anonymous-class-type:
    # list|array|iterable, then $pseudo (non-empty-string…), then positive-int, then
    # @anonymous / AnonymousClass, then $regex. Older candidates assumed iterable sat
    # immediately before @anonymous or positive-int before $regex — both false on a
    # fully patched tree (#36389 / north-star5-fast red).
    if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if "Class generics from PHPStan/Psalm docblocks: App<T>" in text:
    sys.exit(0)

insert_block = """        // Class generics from PHPStan/Psalm docblocks: App<T> (#36382 Slim).
        if (preg_match('/^(.+?)\\s*<.*>\\s*$/s', trim($decl), $genericClass)) {
            return self::fromDecl(trim($genericClass[1]));
        }
"""

candidates = [
    # Preferred: after pseudo ints, before @anonymous (current warm vendor tree).
    (
        """        if (preg_match('/^(positive|negative|non-zero)-int$/', $pseudo)) {
            return new self(self::TYPE_LONG);
        }
        if (preg_match('/@anonymous\\x00/', $decl)) {
""",
        """        if (preg_match('/^(positive|negative|non-zero)-int$/', $pseudo)) {
            return new self(self::TYPE_LONG);
        }
""" + insert_block + """        if (preg_match('/@anonymous\\x00/', $decl)) {
""",
    ),
    # Mid-stack: generics-fallback applied, anonymous-class-type not yet.
    (
        """        if (preg_match('/^(positive|negative|non-zero)-int$/', $pseudo)) {
            return new self(self::TYPE_LONG);
        }
        $regex = """,
        """        if (preg_match('/^(positive|negative|non-zero)-int$/', $pseudo)) {
            return new self(self::TYPE_LONG);
        }
""" + insert_block + """        $regex = """,
    ),
    # Cold-ish: iterable immediately before @anonymous (no intervening $pseudo).
    (
        """        if (preg_match('/^(list|array|iterable)\\s*</i', trim($decl))) {
            return new self(self::TYPE_ARRAY);
        }
        if (preg_match('/@anonymous\\x00/', $decl)) {
""",
        """        if (preg_match('/^(list|array|iterable)\\s*</i', trim($decl))) {
            return new self(self::TYPE_ARRAY);
        }
""" + insert_block + """        if (preg_match('/@anonymous\\x00/', $decl)) {
""",
    ),
    (
        """        if (preg_match('/^(list|array)\\s*</i', trim($decl))) {
            return new self(self::TYPE_ARRAY);
        }
        if (preg_match('/@anonymous\\x00/', $decl)) {
""",
        """        if (preg_match('/^(list|array)\\s*</i', trim($decl))) {
            return new self(self::TYPE_ARRAY);
        }
""" + insert_block + """        if (preg_match('/@anonymous\\x00/', $decl)) {
""",
    ),
]
for anchor, insert in candidates:
    if anchor in text:
        path.write_text(text.replace(anchor, insert, 1))
        break
else:
    sys.stderr.write("php-types-class-generics-fallback: anchor not found in Type.php\n")
    sys.exit(1)
PY
    then
      record_patch_failure "php-types-class-generics-fallback.patch" "overlay anchor not found in Type.php"
      return 1
    fi
    echo "Applied php-types-class-generics-fallback.patch (overlay)"
    return 0
  fi
  if [[ "$(basename "$patch")" == "php-types-fromdecl-trailing-comma.patch" ]]; then
    apply_php_types_fromdecl_trailing_comma_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-docblock-trailing-text.patch" ]]; then
    apply_php_types_docblock_trailing_text_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-callable-return-strip.patch" ]]; then
    apply_php_types_callable_return_strip_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-generic-null-tail.patch" ]]; then
    apply_php_types_generic_null_tail_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-remove-type-empty-union.patch" ]]; then
    apply_php_types_remove_type_empty_union_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-cast-object-resource-stdclass.patch" ]]; then
    apply_php_types_cast_object_resource_stdclass_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-incdec-type.patch" ]]; then
    apply_php_types_incdec_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-yield-from.patch" ]]; then
    apply_php_types_yield_from_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-throw-expr.patch" ]]; then
    apply_php_types_throw_expr_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-never-type.patch" ]]; then
    apply_php_types_never_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-anonymous-class-type.patch" ]]; then
    apply_php_types_anonymous_class_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-ns-func-call.patch" ]]; then
    apply_php_types_ns_func_call_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-arrow-function.patch" ]]; then
    apply_php_types_arrow_function_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-fromdecl-junk-fragments.patch" ]]; then
    apply_php_types_fromdecl_junk_fragments_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-fromdecl-string-literals.patch" ]]; then
    apply_php_types_fromdecl_string_literals_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-magic-script-const.patch" ]]; then
    apply_php_types_magic_script_const_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-closure-unbound-this.patch" ]]; then
    apply_php_types_closure_unbound_this_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-first-class-callable.patch" ]]; then
    apply_php_types_first_class_callable_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-intersection-type.patch" ]]; then
    apply_php_types_intersection_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-union-type.patch" ]]; then
    apply_php_types_union_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-resolver-worklist.patch" ]]; then
    apply_php_types_resolver_worklist_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-list-spread.patch" ]]; then
    apply_php_cfg_list_spread_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-new-first-class-callable.patch" ]]; then
    apply_php_cfg_new_first_class_callable_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-empty-list-assignment.patch" ]]; then
    apply_php_cfg_empty_list_assignment_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-list-mix-keyed-unkeyed.patch" ]]; then
    apply_php_cfg_list_mix_keyed_unkeyed_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-spread.patch" ]]; then
    apply_php_cfg_spread_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-call-arg-site-clone.patch" ]]; then
    apply_php_cfg_call_arg_site_clone_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-simplifier-call-unpack.patch" ]]; then
    apply_php_cfg_simplifier_call_unpack_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-typed-class-const.patch" ]]; then
    apply_php_cfg_typed_class_const_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-class-const-flags.patch" ]]; then
    apply_php_cfg_class_const_flags_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-match.patch" ]]; then
    apply_php_cfg_match_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-property-type.patch" ]]; then
    apply_php_cfg_property_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-ctor-promotion.patch" ]]; then
    apply_php_cfg_ctor_promotion_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-ctor-promotion-readonly.patch" ]]; then
    apply_php_cfg_ctor_promotion_readonly_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-assignop-coalesce.patch" ]]; then
    apply_php_cfg_assignop_coalesce_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-arrow-function.patch" ]]; then
    apply_php_cfg_arrow_function_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-trycatch.patch" ]]; then
    apply_php_cfg_trycatch_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-halt-compiler.patch" ]]; then
    apply_php_cfg_halt_compiler_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-throw-expr.patch" ]]; then
    apply_php_cfg_throw_expr_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-readonly-function.patch" ]]; then
    apply_php_cfg_readonly_function_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-property-readonly.patch" ]]; then
    apply_php_cfg_property_readonly_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-parser-final-property.patch" ]]; then
    apply_php_parser_final_property_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-llvm-memory-buffer-bitcode.patch" ]]; then
    apply_php_llvm_memory_buffer_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-llvm-no-closures-array-map.patch" ]]; then
    apply_php_llvm_no_closures_array_map_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-union-type.patch" ]]; then
    apply_php_cfg_union_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-asymmetric-visibility.patch" ]]; then
    apply_php_cfg_asymmetric_visibility_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-lazy-property.patch" ]]; then
    apply_php_cfg_lazy_property_overlay
    return $?
  fi
  if apply_patch_file_direct "$patch"; then
    return 0
  fi
  case "${patch_name}" in
    php-cfg-match.patch)
      if python3 "$ROOT/script/patch-php-cfg-match.py"; then
        echo "Applied ${patch_name} (python fallback)"
        return 0
      fi
      record_patch_failure "${patch_name}" "match lowering required for self-host spine"
      return 1
      ;;
    php-cfg-strict-types.patch)
      record_patch_failure "${patch_name}" "required for AOT (declare(strict_types))"
      return 1
      ;;
    php-cfg-new-ctor-parens.patch)
      echo "Skip ${patch_name} (optional — stale hunk #6549; does not block throw-expr #6746)"
      return 0
      ;;
    *)
      if patch_marker_present "$patch"; then
        echo "Skip ${patch_name} (already applied)"
        return 0
      fi
      record_patch_failure "${patch_name}"
      return 1
      ;;
  esac
}

case "${1:-}" in
  --verify-pristine)
    verify_pristine_patches
    exit $?
    ;;
  --help|-h)
    echo "Usage: $0 [--verify-only | --verify-pristine]" >&2
    exit 0
    ;;
esac

if [[ "${1:-}" != "--verify-only" ]]; then
reject_hunkless_patches
apply_patch "$PATCH_DIR/php-parser-final-property.patch"
apply_patch "$PATCH_DIR/php-llvm-chooser.patch"
apply_patch "$PATCH_DIR/php-llvm-mcjit-libc-mem.patch"
apply_patch "$PATCH_DIR/php-llvm-mcjit-libc-mem-llvm7.patch"
apply_patch "$PATCH_DIR/php-llvm-no-closures-array-map.patch"
apply_patch "$PATCH_DIR/php-llvm-context-empty-arrays.patch"
apply_patch "$PATCH_DIR/php-llvm-makearray-empty.patch"
apply_patch "$PATCH_DIR/php-llvm-builder-select.patch"
apply_patch "$PATCH_DIR/php-llvm-builder-dispose-idempotent.patch"
apply_patch "$PATCH_DIR/php-llvm-abstract-ffi-global.patch"
apply_patch "$PATCH_DIR/php-llvm-value-addincoming.patch"
apply_patch "$PATCH_DIR/php-llvm-llvmabstract-value-addincoming.patch"
apply_patch "$PATCH_DIR/php-llvm-builder-and-or.patch"
apply_patch "$PATCH_DIR/php-llvm-builder-xor.patch"
repair_php_llvm_assert_env_var
apply_patch "$PATCH_DIR/php-llvm-structgep-assert.patch"
apply_patch "$PATCH_DIR/php-llvm-icmp-assert.patch"
apply_patch "$PATCH_DIR/php-llvm-create-target-machine.patch"
apply_patch "$PATCH_DIR/php-llvm-pass-registry-interface.patch"
apply_patch "$PATCH_DIR/php-llvm-pass-manager-builder-semicolon.patch"
apply_patch "$PATCH_DIR/php-llvm-pass-manager-builder-typed-prop.patch"
apply_patch "$PATCH_DIR/php-llvm-pass-manager-builder-populate.patch"
apply_patch "$PATCH_DIR/php-llvm-module-createfunctionpassmanager.patch"
apply_patch "$PATCH_DIR/php-llvm-memory-buffer-bitcode.patch"
apply_patch "$PATCH_DIR/php-llvm-vector-get-address-space.patch"
apply_patch "$PATCH_DIR/php-llvm-token-type-kind-typo.patch"
apply_patch "$PATCH_DIR/php-llvm-void-star-pointer-i8.patch"
apply_patch "$PATCH_DIR/php-llvm-function-getbasicblocks-nparams-typo.patch"
apply_patch "$PATCH_DIR/php-llvm-x86-posix-fallback.patch"
repair_php_llvm_token_type_kind_typo_in_prelinked

# php-cfg before php-types: php-types-mixed-reserved.patch references Op\Type\Mixed_.
if [[ -d "$ROOT/vendor/ircmaxell/php-cfg" ]]; then
  # __TRAIT__ scope (traitStack overlay) must run before patches that can fail early (#3640).
  apply_php_cfg_magic_constants_overlay || true
  # PHP 8.3 `in` operator CFG node must survive optional patch failures (#4682, #4850).
  apply_php_cfg_in_operator_overlay || true
  apply_php_cfg_exit_two_arg_overlay || true
  apply_php_cfg_void_cast_overlay || true
  # PHP 8.3 typed class/trait constants must survive optional patch failures (#6012).
  apply_php_cfg_typed_class_const_overlay || true
  # listSpreadRhs on Assign must exist before optional patch failures abort the script (#6069, #4835).
  apply_php_cfg_list_spread_overlay
  # ++/-- overlays are hard-required — missing PostInc arms break compile (#6326, #6321).
  apply_php_cfg_incdec_expr_overlay
  apply_php_types_incdec_type_overlay
  # Spine uses match + ??= + union types — apply before optional patches that can abort under set -e.
  apply_php_cfg_match_overlay
  apply_php_cfg_assignop_coalesce_overlay
  apply_php_cfg_union_type_overlay
  apply_php_types_union_type_overlay || true
  # Readonly closure FLAG_READONLY must exist before any closure compile (#7464, #7428).
  apply_php_cfg_readonly_function_overlay || true
  # Throw expressions must survive optional patch failures (#6746, #5151).
  apply_php_cfg_throw_expr_overlay
  apply_php_types_throw_expr_overlay
  # Required for readonly property VM/JIT guards (#3149, #4518); apply before optional patches may fail.
  apply_php_cfg_property_readonly_overlay
  apply_patch "$PATCH_DIR/php-cfg-dollars-brace.patch"
  apply_patch "$PATCH_DIR/php-cfg-mixed-reserved.patch"
  apply_patch "$PATCH_DIR/php-cfg-nullsafe.patch"
  apply_patch "$PATCH_DIR/php-cfg-nullsafe-parser.patch"
  apply_patch "$PATCH_DIR/php-cfg-error-suppress-read.patch"
  apply_patch "$PATCH_DIR/php-cfg-bare-variable-read-stmt.patch"
  apply_patch "$PATCH_DIR/php-cfg-error-suppress-cv-temp.patch"
  apply_patch "$PATCH_DIR/php-cfg-error-suppress-simplifier.patch"
  apply_patch "$PATCH_DIR/php-cfg-simplifier-call-unpack.patch" || true
  apply_patch "$PATCH_DIR/php-cfg-strict-types.patch"
  apply_patch "$PATCH_DIR/php-cfg-goto-scope.patch"
  apply_patch "$PATCH_DIR/php-cfg-goto-scope-jumptable.patch"
  apply_patch "$PATCH_DIR/php-cfg-trycatch.patch"
  apply_php_cfg_process_assertions_overlay || true
  apply_patch "$PATCH_DIR/php-cfg-phi-resolver-null.patch"
  apply_patch "$PATCH_DIR/php-cfg-phi-resolver-skip-forwarded.patch"
  apply_patch "$PATCH_DIR/php-cfg-magic-constants.patch"
  apply_patch "$PATCH_DIR/php-cfg-magic-script-const.patch"
  apply_patch "$PATCH_DIR/php-cfg-declare-ticks.patch"
  apply_patch "$PATCH_DIR/php-cfg-magic-line.patch"
  apply_patch "$PATCH_DIR/php-cfg-switch-cond-property.patch"
  apply_patch "$PATCH_DIR/php-cfg-loop-resolver-nested.patch"
  apply_patch "$PATCH_DIR/php-cfg-loop-resolver-continue-switch-warning.patch"
  apply_patch "$PATCH_DIR/php-cfg-loop-resolver-break-outside-context.patch"
  apply_patch "$PATCH_DIR/php-cfg-loop-resolver-break-continue-positive.patch"
  apply_patch "$PATCH_DIR/php-cfg-no-arrow-function.patch"
  apply_patch "$PATCH_DIR/php-cfg-no-closure-preg-replace-callback.patch"
  apply_patch "$PATCH_DIR/php-cfg-property-type.patch"
  apply_php_cfg_enum_early_chain
  apply_patch "$PATCH_DIR/php-cfg-typed-class-const.patch"
  apply_patch "$PATCH_DIR/php-cfg-class-const-flags.patch"
  # typed-class-const overlay copies Const_.php without enum markers; restore (#6622).
  apply_php_cfg_enum_class_const_overlay || true
  apply_patch "$PATCH_DIR/php-cfg-asymmetric-visibility.patch"
  apply_patch "$PATCH_DIR/php-cfg-assertion-expr-property.patch"
  apply_php_cfg_yield_from_overlay
  apply_php_cfg_shell_exec_overlay
  apply_patch "$PATCH_DIR/php-cfg-incdec-expr.patch"
  apply_patch "$PATCH_DIR/php-cfg-yield-keyed.patch"
  apply_patch "$PATCH_DIR/php-cfg-match.patch"
  apply_patch "$PATCH_DIR/php-cfg-match-multi-cond-block.patch"
  apply_patch "$PATCH_DIR/php-cfg-halt-compiler.patch"
  apply_php_cfg_compiler_halt_offset_overlay
  apply_patch "$PATCH_DIR/php-cfg-assignop-coalesce.patch"
  apply_patch "$PATCH_DIR/php-cfg-list-destruct-byref.patch"
  apply_patch "$PATCH_DIR/php-cfg-list-assignment-attr.patch"
  apply_patch "$PATCH_DIR/php-cfg-empty-list-assignment.patch"
  apply_patch "$PATCH_DIR/php-cfg-list-mix-keyed-unkeyed.patch"
  apply_patch "$PATCH_DIR/php-cfg-list-skip-slot.patch" || true
  apply_patch "$PATCH_DIR/php-cfg-list-spread.patch"
  apply_patch "$PATCH_DIR/php-cfg-first-class-callable.patch"
  apply_patch "$PATCH_DIR/php-cfg-anonymous-class.patch"
  apply_patch "$PATCH_DIR/php-cfg-new-first-class-callable.patch"
  apply_patch "$PATCH_DIR/php-cfg-nullsafe-first-class-callable.patch"
  apply_patch "$PATCH_DIR/php-cfg-arrow-function.patch"
  apply_patch "$PATCH_DIR/php-cfg-new-ctor-parens.patch"
  apply_php_cfg_anonymous_class_name_overlay || true
  apply_patch "$PATCH_DIR/php-cfg-enum.patch"
  apply_patch "$PATCH_DIR/php-cfg-enum-implements.patch"
  apply_patch "$PATCH_DIR/php-cfg-enum-class-method.patch"
  apply_patch "$PATCH_DIR/php-cfg-enum-abstract.patch"
  apply_patch "$PATCH_DIR/php-cfg-named-args.patch"
  apply_patch "$PATCH_DIR/php-cfg-call-time-pass-by-ref.patch"
  apply_patch "$PATCH_DIR/php-cfg-spread.patch"
  apply_patch "$PATCH_DIR/php-cfg-call-arg-site-clone.patch" || true
  apply_patch "$PATCH_DIR/php-cfg-never-type.patch"
  apply_patch "$PATCH_DIR/php-cfg-intersection-type.patch"
  apply_patch "$PATCH_DIR/php-cfg-instanceof-union.patch"
  apply_patch "$PATCH_DIR/php-cfg-union-type.patch"
  apply_patch "$PATCH_DIR/php-cfg-ctor-promotion.patch"
  apply_patch "$PATCH_DIR/php-cfg-ctor-promotion-readonly.patch"
  apply_patch "$PATCH_DIR/php-cfg-readonly-function.patch"
  apply_patch "$PATCH_DIR/php-cfg-property-readonly.patch"
  apply_php_cfg_asymmetric_set_visibility_parser_overlay
  apply_php_cfg_asymmetric_get_visibility_parser_overlay
  apply_php_cfg_lazy_property_overlay
  apply_php_cfg_global_typed_const_overlay
  apply_php_cfg_global_deprecated_const_overlay
  apply_php_cfg_typed_function_static_overlay
  apply_patch "$PATCH_DIR/php-cfg-typed-function-static.patch"
  apply_patch "$PATCH_DIR/php-cfg-attribute-groups.patch"
  apply_patch "$PATCH_DIR/php-cfg-trait-use.patch"
  apply_php_cfg_trait_use_overlay
  apply_patch "$PATCH_DIR/php-cfg-throw-expr.patch"
  apply_patch "$PATCH_DIR/php-cfg-is-resource-no-assertion.patch"
  apply_patch "$PATCH_DIR/php-cfg-assertion-fn-arity.patch"
  # Perf patches last: their hunks are diffed against the fully-patched files (#16077).
  apply_php_cfg_simplifier_use_chain_overlay
  apply_patch "$PATCH_DIR/php-cfg-operand-usage-dedup.patch"
fi

# PHP 8.4 implicitly-nullable params in php-cfg / php-optimizer (35 params / 23 files).
# Must run AFTER php-cfg overlays that rewrite the same signatures (Exit_, Property, StaticVar,
# Parser, …) — hunks are diffed against post-overlay vendor. Harmless on 8.2 (`?Type` since 7.1).
# Matching lib/Visitor/* declarations were fixed directly (#24972).
apply_patch "$PATCH_DIR/php-vendor-implicit-nullable-84.patch"
apply_patch "$PATCH_DIR/php-cfg-class-optional-param-order.patch"

if [[ -d "$ROOT/vendor/ircmaxell/php-types" ]]; then
  apply_php_types_incdec_type_overlay
  apply_php_types_arrow_function_overlay
  apply_patch "$PATCH_DIR/php-types-binaryop-pow.patch"
  apply_patch "$PATCH_DIR/php-types-binaryop-coalesce.patch"
  apply_patch "$PATCH_DIR/php-types-cast-object.patch"
  apply_patch "$PATCH_DIR/php-types-cast-object-resource-stdclass.patch"
  apply_patch "$PATCH_DIR/php-types-cast-unset.patch"
  apply_patch "$PATCH_DIR/php-types-binaryop-spaceship.patch"
  apply_php_types_hex2bin_strict_overlay
  apply_patch "$PATCH_DIR/php-types-str-bool-fns.patch"
  apply_patch "$PATCH_DIR/php-types-addcslashes-characters.patch"
  apply_patch "$PATCH_DIR/php-types-proc-open-array-string.patch"
  apply_patch "$PATCH_DIR/php-types-str-incdec.patch"
  apply_patch "$PATCH_DIR/php-types-explode-array-return.patch"
  apply_patch "$PATCH_DIR/php-types-splfixedarray-fromarray-return.patch"
  apply_patch "$PATCH_DIR/php-types-readfile-int-false.patch"
  apply_patch "$PATCH_DIR/php-types-file-array-false.patch"
  apply_patch "$PATCH_DIR/php-types-get-meta-tags-array-false.patch"
  apply_patch "$PATCH_DIR/php-types-array-combine-array-false.patch"
  apply_patch "$PATCH_DIR/php-types-stream-context-array-return.patch"
  apply_patch "$PATCH_DIR/php-types-libxml-get-errors-array-return.patch"
  apply_patch "$PATCH_DIR/php-types-dom-removeattributenode-return.patch"
  apply_patch "$PATCH_DIR/php-types-strpbrk-string-false.patch"
  apply_patch "$PATCH_DIR/php-types-error-get-last-null.patch"
  apply_patch "$PATCH_DIR/php-types-crc32-int.patch"
  apply_patch "$PATCH_DIR/php-types-get-declared-functions.patch"
  apply_patch "$PATCH_DIR/php-types-realpath-cache-get-array.patch"
  apply_patch "$PATCH_DIR/php-types-realpath-cache-size-int.patch"
  apply_patch "$PATCH_DIR/php-types-get-declared-exclude-deprecated.patch"
  apply_patch "$PATCH_DIR/php-types-gettimeofday-float.patch"
  apply_patch "$PATCH_DIR/php-types-round-float.patch"
  apply_patch "$PATCH_DIR/php-types-link-bool.patch"
  apply_patch "$PATCH_DIR/php-types-gc-enabled-bool.patch"
  apply_patch "$PATCH_DIR/php-types-curl-version-arity.patch"
  apply_patch "$PATCH_DIR/php-types-sem-get-auto-release-bool.patch"
  apply_patch "$PATCH_DIR/php-types-openssl-encrypt-aead-args.patch"
  apply_patch "$PATCH_DIR/php-types-openssl-cms-verify-arginfo.patch"
  apply_patch "$PATCH_DIR/php-types-intltz-get-iana-id-arginfo.patch"
  apply_patch "$PATCH_DIR/php-types-mysqli-fetch-column-arginfo.patch"
  apply_patch "$PATCH_DIR/php-types-soap-dorequest-arginfo.patch"
  apply_patch "$PATCH_DIR/php-types-ldap-get-option-byref.patch"
  apply_patch "$PATCH_DIR/php-types-hash-init-arginfo.patch"
  apply_patch "$PATCH_DIR/php-types-json-decode-flags-arginfo.patch"
  apply_patch "$PATCH_DIR/php-types-idn-arginfo.patch"
  apply_patch "$PATCH_DIR/php-types-dollars-brace.patch"
  apply_patch "$PATCH_DIR/php-types-missing-parent-no-echo.patch"
  apply_patch "$PATCH_DIR/php-types-mixed-reserved.patch"
  apply_patch "$PATCH_DIR/php-types-nullsafe.patch"
  apply_php_types_static_var_array_type_repair
  apply_patch "$PATCH_DIR/php-types-static-var.patch"
  apply_patch "$PATCH_DIR/php-types-nullable-return.patch"
  # Never type needs mixed-reserved + nullable-return fromTypeDecl arms (#8738).
  apply_patch "$PATCH_DIR/php-types-never-type.patch"
  apply_patch "$PATCH_DIR/php-types-cfg-reference.patch"
  apply_patch "$PATCH_DIR/php-types-nullable-optype-return.patch"
  apply_patch "$PATCH_DIR/php-types-yield-from.patch"
  apply_patch "$PATCH_DIR/php-types-fromvalue-null.patch"
  apply_patch "$PATCH_DIR/php-types-doc-comment-string.patch"
  apply_patch "$PATCH_DIR/php-types-docblock-first-token.patch"
  apply_patch "$PATCH_DIR/php-types-array-shape.patch"
  apply_patch "$PATCH_DIR/php-types-generics-fallback.patch"
  apply_patch "$PATCH_DIR/php-types-generics-list-array.patch"
  apply_patch "$PATCH_DIR/php-types-iterable-generic.patch"
  apply_patch "$PATCH_DIR/php-types-docblock-trailing-text.patch"
  apply_patch "$PATCH_DIR/php-types-fromdecl-trailing-comma.patch"
  apply_patch "$PATCH_DIR/php-types-callable-return-strip.patch"
  apply_patch "$PATCH_DIR/php-types-generic-null-tail.patch"
  apply_patch "$PATCH_DIR/php-types-fromdecl-junk-fragments.patch"
  apply_patch "$PATCH_DIR/php-types-fromdecl-string-literals.patch"
  apply_patch "$PATCH_DIR/php-types-remove-type-empty-union.patch"
  apply_patch "$PATCH_DIR/php-types-anonymous-class-type.patch"
  # After list|array|iterable + @anonymous exist (#36382). Applying earlier
  # makes the overlay look for anchors that generics-list-array / iterable /
  # anonymous-class-type have not inserted yet (cold CI exit 1).
  apply_patch "$PATCH_DIR/php-types-class-generics-fallback.patch"
  apply_patch "$PATCH_DIR/php-types-ns-func-call.patch"
  apply_patch "$PATCH_DIR/php-types-arrow-function.patch"
  apply_patch "$PATCH_DIR/php-types-closure-unbound-this.patch"
  apply_patch "$PATCH_DIR/php-types-magic-script-const.patch"
  apply_patch "$PATCH_DIR/php-types-first-class-callable.patch"
  apply_patch "$PATCH_DIR/php-types-incdec-type.patch"
  apply_patch "$PATCH_DIR/php-types-intersection-type.patch"
  apply_patch "$PATCH_DIR/php-types-union-type.patch"
  apply_patch "$PATCH_DIR/php-types-throw-expr.patch"
  apply_php_types_fcc_overlay_final_repair
  apply_php_types_compiler_halt_offset_overlay
  # Perf patch last: diffed against the fully-patched TypeReconstructor.php (#16077).
  apply_php_types_resolver_worklist_overlay
fi

if [[ -d "$ROOT/vendor/pre/plugin" ]]; then
  apply_patch "$PATCH_DIR/pre-plugin-parser-macros.patch"
  apply_patch "$PATCH_DIR/pre-plugin-autoload-prepend.patch"
fi

if ((${#APPLY_PATCH_FAILURES[@]} > 0)); then
  echo "apply-patches: ${#APPLY_PATCH_FAILURES[@]} patch(es) failed: ${APPLY_PATCH_FAILURES[*]}" >&2
  exit 1
fi
fi

# Language capabilities (#3802 throw expr, #3094 union, #3149 readonly) must survive harness tar-copy.
verify_critical_language_patches() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local recon="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local prop="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php"
  local missing=()

  if [[ ! -d "$ROOT/vendor/ircmaxell/php-cfg" || ! -d "$ROOT/vendor/ircmaxell/php-types" ]]; then
    return 0
  fi
  if ! grep -qE 'parseExpr_Throw|Op\\Expr\\Throw_' "$parser" 2>/dev/null; then
    missing+=("php-cfg-throw-expr")
  fi
  if grep -q 'function parseEnumCase' "$parser" 2>/dev/null \
    && ! grep -q 'enumCaseHasExplicitValue' "$parser" 2>/dev/null; then
    missing+=("php-cfg-enum-case-explicit-value")
  fi
  local const_file="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Const_.php"
  if [[ -f "$const_file" ]] \
    && grep -q 'function parseEnumCase' "$parser" 2>/dev/null \
    && ! grep -q 'enumCaseHasExplicitValue' "$const_file" 2>/dev/null; then
    missing+=("php-cfg-enum-case-explicit-value-Const_")
  fi
  if [[ -f "$const_file" ]] \
    && grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null \
    && ! grep -q 'public bool \$isEnumCase = false' "$const_file" 2>/dev/null; then
    missing+=("php-cfg-enum-class-const-Const_")
  fi
  if grep -q 'function parseStmt_TraitUse' "$parser" 2>/dev/null; then
    if grep -A8 'function parseStmt_TraitUse' "$parser" 2>/dev/null | grep -q '// TODO'; then
      missing+=("php-cfg-trait-use")
    elif ! [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/TraitUse.php" ]]; then
      missing+=("php-cfg-trait-use-Op")
    fi
  else
    missing+=("php-cfg-trait-use")
  fi
  if grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    if ! grep -A35 'function parseStmt_Enum' "$parser" 2>/dev/null | grep -q 'Stmt\\TraitUse'; then
      missing+=("php-cfg-enum-trait-use")
    fi
    if ! grep -A35 'function parseStmt_Enum' "$parser" 2>/dev/null | grep -q 'Stmt\\ClassConst'; then
      missing+=("php-cfg-enum-class-const-Parser")
    fi
    if ! grep -A20 'function parseEnumCase' "$parser" 2>/dev/null | grep -q 'isEnumCase = true'; then
      missing+=("php-cfg-enum-case-isEnumCase")
    fi
  fi
  local vendor_type="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if [[ -f "$vendor_type" ]] && php_types_type_fromdecl_trailing_comma_corrupt "$vendor_type"; then
    missing+=("php-types-fromdecl-trailing-comma-corrupt")
  fi
  if ! grep -q 'instanceof Op\\Type\\Union_' "$recon" 2>/dev/null; then
    missing+=("php-types-union-type")
  elif ! php -l "$recon" >/dev/null 2>&1; then
    missing+=("php-types-union-type-syntax")
  fi
  if [[ -f "$vendor_type" ]] && ! grep -q 'instanceof CfgType\\Union_' "$vendor_type" 2>/dev/null; then
    missing+=("php-types-union-type-Type")
  fi
  if ! grep -q 'instanceof Op\\Type\\Intersection' "$recon" 2>/dev/null; then
    missing+=("php-types-intersection-type")
  fi
  if ! grep -q "case 'Expr_MagicScriptConst':" "$recon" 2>/dev/null; then
    missing+=("php-types-magic-script-const")
  fi
  if [[ -f "$vendor_type" ]] && ! grep -q 'instanceof CfgType\\Intersection' "$vendor_type" 2>/dev/null; then
    missing+=("php-types-intersection-type-Type")
  fi
  if ! grep -qE 'public \$readonly|propertyFlags' "$prop" 2>/dev/null; then
    missing+=("php-cfg-property-readonly-Property")
  fi
  if ! grep -qE 'propertyFlags = \$node->flags|\$cfgProp->readonly =|\$prop->readonly =|\$property->readonly =|->readonly = 0 !== \\(\\$node->flags & .*MODIFIER_READONLY\\)' "$parser" 2>/dev/null; then
    missing+=("php-cfg-property-readonly-Parser")
  fi
  local func_file="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Func.php"
  if [[ -f "$func_file" ]] && ! grep -q 'FLAG_READONLY' "$func_file" 2>/dev/null; then
    missing+=("php-cfg-readonly-function-Func")
  fi
  if grep -q 'function parseExpr_Closure' "$parser" 2>/dev/null \
    && ! grep -A25 'function parseExpr_Closure' "$parser" 2>/dev/null \
      | grep -q "compilerReadonlyFunction"; then
    missing+=("php-cfg-readonly-function-Parser")
  fi
  if grep -q 'function parseExpr_ArrowFunction' "$parser" 2>/dev/null \
    && ! grep -A25 'function parseExpr_ArrowFunction' "$parser" 2>/dev/null \
      | grep -q "compilerReadonlyFunction"; then
    missing+=("php-cfg-readonly-function-ArrowParser")
  fi
  if ! grep -q 'function extractAsymmetricSetVisibilityFromAttributes' "$parser" 2>/dev/null; then
    missing+=("php-cfg-asymmetric-set-visibility-Parser")
  fi
  if ! grep -q 'function extractAsymmetricGetVisibilityFromAttributes' "$parser" 2>/dev/null; then
    missing+=("php-cfg-asymmetric-get-visibility-Parser")
  fi
  if ! grep -q 'function extractLazyPropertyFromAttributes' "$parser" 2>/dev/null; then
    missing+=("php-cfg-lazy-property-Parser")
  fi
  if ! grep -q "case 'Expr_YieldFrom':" "$recon" 2>/dev/null; then
    missing+=("php-types-yield-from")
  fi
  if [[ ! -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/In_.php" ]]; then
    missing+=("php-cfg-in-operator-In_")
  fi
  if [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Exit_.php" ]] \
    && ! grep -q 'public \$message' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Exit_.php" 2>/dev/null; then
    missing+=("php-cfg-exit-two-arg-Exit_")
  fi
  local assign_expr="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Assign.php"
  if [[ -f "$assign_expr" ]] && ! grep -q 'listSpreadRhs' "$assign_expr" 2>/dev/null; then
    missing+=("php-cfg-list-spread-Assign")
  fi
  if grep -q 'function parseListAssignment' "$parser" 2>/dev/null \
    && ! grep -q 'listSpreadExcludedKeys = \$excludedKeys' "$parser" 2>/dev/null; then
    missing+=("php-cfg-list-spread-Parser")
  fi
  if [[ -f "$const_file" ]] \
    && ! grep -q 'public ?Type \$declaredType' "$const_file" 2>/dev/null; then
    missing+=("php-cfg-typed-class-const-Const_")
  fi
  if grep -q 'function parseStmt_ClassConst' "$parser" 2>/dev/null \
    && ! grep -q 'declaredType = null !== \$node->type' "$parser" 2>/dev/null; then
    missing+=("php-cfg-typed-class-const-Parser")
  fi
  if ! grep -q 'new Op\\Expr\\PostInc' "$parser" 2>/dev/null \
    || [[ ! -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/PostInc.php" ]]; then
    missing+=("php-cfg-incdec-expr")
  fi
  if ! grep -q "case 'Expr_PostInc':" "$recon" 2>/dev/null; then
    missing+=("php-types-incdec-type")
  fi
  if ! grep -q 'function resolveOp_Expr_ArrowFunction' "$recon" 2>/dev/null; then
    missing+=("php-types-arrow-function")
  fi
  if ! grep -q 'FirstClassCallable::KIND_METHOD' "$recon" 2>/dev/null; then
    missing+=("php-types-first-class-callable")
  elif grep -q 'return \[Type::array()\];' "$recon" 2>/dev/null; then
    missing+=("php-types-first-class-callable-Type-array-typo")
  elif ! grep -q 'new Type(Type::TYPE_ARRAY)' "$recon" 2>/dev/null; then
    missing+=("php-types-first-class-callable-TYPE_ARRAY")
  fi
  if ! grep -q "case 'Expr_Throw':" "$recon" 2>/dev/null; then
    missing+=("php-types-throw-expr")
  fi
  # php-src ext/dom/php_dom.stub.php — removeAttributeNode(): DOMAttr (not PHP 5 bool) (#32707)
  if ! grep -qF "'DOMElement::removeAttributeNode' => ['DOMAttr'" \
    "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null; then
    missing+=("php-types-dom-removeattributenode-return")
  fi
  local trycatch="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/TryCatch.php"
  if grep -q 'function parseStmt_TryCatch' "$parser" 2>/dev/null; then
    if ! grep -q '\$elseBlock ?? \$endBlock' "$parser" 2>/dev/null; then
      missing+=("php-cfg-trycatch-else-Parser")
    fi
    if [[ -f "$trycatch" ]] && ! grep -q 'public \$else;' "$trycatch" 2>/dev/null; then
      missing+=("php-cfg-trycatch-else-TryCatch")
    fi
  fi
  if ! grep -q 'gotoLabelScopes' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/FuncContext.php" 2>/dev/null \
    || ! grep -q 'function validateGotoScope' "$parser" 2>/dev/null; then
    missing+=("php-cfg-goto-scope")
  fi
  if ! grep -q 'Jumptable path must track switch scope' "$parser" 2>/dev/null \
    || ! grep -A6 'function validateGotoScope' "$parser" 2>/dev/null | grep -q 'throw new \\CompileError'; then
    missing+=("php-cfg-goto-scope-jumptable")
  fi
  local type_php="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if grep -q "case 'Expr_Throw':" "$recon" 2>/dev/null \
    && { ! grep -q 'function never(): self' "$type_php" 2>/dev/null \
      || ! grep -q 'instanceof CfgType\\Never_' "$type_php" 2>/dev/null \
      || ! grep -q "case 'never':" "$type_php" 2>/dev/null; }; then
    missing+=("php-types-never-type")
  fi
  local prelinked_recon="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  if [[ -f "$prelinked_recon" ]] && ! grep -q "case 'Expr_PostInc':" "$prelinked_recon" 2>/dev/null; then
    missing+=("php-types-incdec-type-prelinked")
  fi
  if [[ -f "$prelinked_recon" ]] && ! grep -q 'FirstClassCallable::KIND_METHOD' "$prelinked_recon" 2>/dev/null; then
    missing+=("php-types-first-class-callable-prelinked")
  elif [[ -f "$prelinked_recon" ]] && grep -q 'return \[Type::array()\];' "$prelinked_recon" 2>/dev/null; then
    missing+=("php-types-first-class-callable-prelinked-Type-array-typo")
  fi
  if [[ -f "$prelinked_recon" ]] && ! grep -q "case 'Expr_Throw':" "$prelinked_recon" 2>/dev/null; then
    missing+=("php-types-throw-expr-prelinked")
  fi
  if [[ -f "$prelinked_recon" ]] && ! grep -q 'instanceof Op\\Type\\Union_' "$prelinked_recon" 2>/dev/null; then
    missing+=("php-types-union-type-prelinked")
  elif [[ -f "$prelinked_recon" ]] && ! php -l "$prelinked_recon" >/dev/null 2>&1; then
    missing+=("php-types-union-type-prelinked-syntax")
  fi
  if [[ -f "$prelinked_recon" ]] && ! grep -q 'instanceof Op\\Type\\Intersection' "$prelinked_recon" 2>/dev/null; then
    missing+=("php-types-intersection-type-prelinked")
  fi
  local prelinked_type="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if [[ -f "$prelinked_type" ]] && ! grep -q 'instanceof CfgType\\Union_' "$prelinked_type" 2>/dev/null; then
    missing+=("php-types-union-type-Type-prelinked")
  fi
  if [[ -f "$prelinked_type" ]] && ! grep -q 'instanceof CfgType\\Intersection' "$prelinked_type" 2>/dev/null; then
    missing+=("php-types-intersection-type-Type-prelinked")
  fi
  if [[ -f "$prelinked_type" ]] \
    && grep -q "throw new \\\\LogicException('Unknown type encountered')" "$prelinked_type" 2>/dev/null; then
    missing+=("php-types-remove-type-empty-union-prelinked")
  fi
  if ((${#missing[@]} > 0)); then
    echo "apply-patches: critical language patch markers missing: ${missing[*]}" >&2
    echo "apply-patches: hint: php-types-incdec-type overlay anchor drift — see #6321" >&2
    echo "apply-patches: hint: php-types-first-class-callable overlay — run composer install && ./script/apply-patches.sh (#6932)" >&2
    exit 1
  fi
}
verify_critical_language_patches
