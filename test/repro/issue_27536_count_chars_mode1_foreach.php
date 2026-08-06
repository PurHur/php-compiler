<?php
/**
 * #27536 — AOT count_chars mode 1 must foreach like Zend (skip packed holes).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(count_chars)
 */
$r = count_chars('aba', 1);
ksort($r);
foreach ($r as $k => $v) {
    echo chr($k), $v, ';';
}
echo "\n";
