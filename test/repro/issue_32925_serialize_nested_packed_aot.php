<?php
/**
 * #32925 — AOT serialize() nested packed arrays under string keys must not SIGABRT.
 * Follow-up to #32911 (flat keys fixed; NestedJIT toArray on pair values still aborted).
 * php-src: ext/standard/var.c php_var_serialize_intern
 */
echo serialize(['a' => 1, 'b' => [2]]), PHP_EOL;
echo serialize(['b' => [2, 3]]), PHP_EOL;
