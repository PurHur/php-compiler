<?php
/**
 * #32911 — AOT serialize() string keys must use s:len:"…"; INF/NAN uppercase.
 * php-src: ext/standard/var.c php_var_serialize_intern
 *
 * Nested arrays still SIGABRT on master (separate NestedJIT toArray recurse) — not asserted here.
 */
echo serialize(['a' => 1]), PHP_EOL;
echo serialize(['hello' => 'world', 'x' => 2]), PHP_EOL;
var_dump(serialize(INF));
var_dump(serialize(NAN));
