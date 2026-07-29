<?php
/**
 * #24836 — early-bind must not treat ClassMethod as file-level Function_.
 *
 * php-cfg ClassMethod extends Function_, and ClassLike exposes stmts via getSubBlocks().
 * Walking {main} with `instanceof Function_` therefore collected interface methods
 * (null cfg) and called compileCfgBlock(null) — TypeError — which blocked every
 * ext/spl spine-chunk compile after #24807 (previously surfaced as SIGSEGV further
 * along the SPINE_CHUNK path).
 *
 * Expect: compile succeeds (exit 0). Zend prints "ok".
 */
interface Issue24836Live
{
    public function rewind(): void;

    public function next(): void;
}

function issue_24836_ok(): string
{
    return 'ok';
}

echo issue_24836_ok(), "\n";
