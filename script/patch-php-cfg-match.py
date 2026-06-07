#!/usr/bin/env python3
"""Insert or refresh parseExpr_Match in vendor php-cfg Parser (#143, #3398, #5448)."""
from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PARSER = ROOT / "vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
OVERLAY = ROOT / "patches/overlays/php-cfg/match-parser-methods.php"

INSERT = OVERLAY.read_text() if OVERLAY.is_file() else ""

NEEDLE = "    protected function parseExpr_UnaryMinus(Expr\\UnaryMinus $expr)\n"
OVERLAY_COMMENT = "    /**\n     * Lower match to === compare"
MATCH_FN = "    protected function parseExpr_Match(Expr\\Match_ $expr)\n"


def match_block_start(text: str) -> int | None:
    if OVERLAY_COMMENT in text:
        return text.index(OVERLAY_COMMENT)
    if MATCH_FN in text:
        return text.index(MATCH_FN)
    return None


def main() -> int:
    if not PARSER.is_file():
        print(f"missing {PARSER}", flush=True)
        return 1
    if not INSERT.strip():
        print(f"missing overlay {OVERLAY}", flush=True)
        return 1
    text = PARSER.read_text()
    if "lowerUnhandledMatchError" in text and "function parseExpr_Match" in text:
        if "phpc_match_unhandled_operand_is_object" in text:
            print(f"skip {PARSER} (match overlay current)")
            return 0
        print(f"refresh {PARSER} (match overlay stale — enum UnhandledMatchError probe)")

    start = match_block_start(text)
    if start is not None:
        if NEEDLE not in text[start:]:
            print("parseExpr_UnaryMinus needle missing after parseExpr_Match", flush=True)
            return 1
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
