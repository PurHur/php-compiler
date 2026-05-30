#!/usr/bin/env python3
"""Insert parseExpr_Match into vendor php-cfg Parser (issue #143, #3398)."""
from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PARSER = ROOT / "vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
OVERLAY = ROOT / "patches/overlays/php-cfg/match-parser-methods.php"

INSERT = OVERLAY.read_text() if OVERLAY.is_file() else ""

NEEDLE = "    protected function parseExpr_UnaryMinus(Expr\\UnaryMinus $expr)\n"


def main() -> int:
    if not PARSER.is_file():
        print(f"missing {PARSER}", flush=True)
        return 1
    if not INSERT.strip():
        print(f"missing overlay {OVERLAY}", flush=True)
        return 1
    text = PARSER.read_text()
    if "function parseExpr_Match" in text:
        start = text.index("    /**\n     * Lower match to === compare")
        end = text.index(NEEDLE, start)
        text = text[:start] + INSERT + text[end:]
    elif NEEDLE not in text:
        print("needle not found in Parser.php", flush=True)
        return 1
    else:
        text = text.replace(NEEDLE, INSERT + NEEDLE, 1)
    PARSER.write_text(text)
    print("patched", PARSER)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
