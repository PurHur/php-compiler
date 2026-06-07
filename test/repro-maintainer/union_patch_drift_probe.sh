#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
source script/php-env.sh

RECON="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
TYPE="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
cp "$RECON" /tmp/recon.bak
cp "$TYPE" /tmp/type.bak

cleanup() {
  cp /tmp/type.bak "$TYPE"
  cp /tmp/recon.bak "$RECON"
}
trap cleanup EXIT

python3 - "$TYPE" <<'PY'
import sys
from pathlib import Path
p = Path(sys.argv[1])
t = p.read_text()
start = t.find("        if ($decl instanceof CfgType\\Union_) {")
end = t.find("        if ($decl instanceof CfgType\\Intersection) {")
if start < 0 or end <= start:
    raise SystemExit("Union_ block not found in Type.php")
p.write_text(t[:start] + t[end:])
PY

php -r '
require "vendor/autoload.php";
$codes = [
    "property" => "<?php class C { public int|string \$p; }",
    "param" => "<?php function f(int|string \$x): void {}",
];
foreach ($codes as $label => $code) {
    try {
        $r = new PHPCompiler\Runtime();
        $r->parseAndCompile($code, "t.php");
        echo $label, ": compile ok\n";
    } catch (Throwable $e) {
        echo $label, ": ", get_class($e), ": ", $e->getMessage(), "\n";
    }
}
'

echo "--- apply-patches after Type.php strip ---"
./script/apply-patches.sh >/dev/null
grep -q 'instanceof CfgType\\Union_' "$TYPE" && echo "Type.php Union_ restored" || { echo "Type.php Union_ STILL MISSING"; exit 1; }

php <<'PHP'
<?php
require "vendor/autoload.php";
try {
    $r = new PHPCompiler\Runtime();
    $r->parseAndCompile("<?php class C { public int|string \$p; }", "t.php");
    echo "property compile ok after apply-patches\n";
} catch (Throwable $e) {
    echo "property after repair: ", get_class($e), ": ", $e->getMessage(), "\n";
    exit(1);
}
PHP
