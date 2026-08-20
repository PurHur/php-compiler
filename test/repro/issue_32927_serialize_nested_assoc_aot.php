<?php
/**
 * #32927 — AOT serialize() nested assoc string keys must keep s:len:"…"; (re-#32925).
 * php-src: ext/standard/var.c php_var_serialize_intern
 */
echo serialize(['c' => ['d' => 3]]), PHP_EOL;
echo serialize(['a' => 1, 'b' => [2]]), PHP_EOL;
echo serialize(['outer' => ['x' => 'y', 'n' => 7]]), PHP_EOL;
