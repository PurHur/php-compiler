<?php
/**
 * Nested unset($a[0]['name']) then array_key_exists must be false under AOT (#36732).
 *
 * php-src: ext/standard/array.c php_array_key_exists / zend_hash_exists
 * after zend_hash_del (Zend/zend_hash.c).
 */
$a = [['name' => 'p', 'handler' => 1]];
unset($a[0]['name']);
echo isset($a[0]['name']) ? "isset=true\n" : "isset=false\n";
echo implode(',', array_keys($a[0])), "\n";
echo array_key_exists('name', $a[0]) ? "ake=true\n" : "ake=false\n";

$f = ['name' => 'p', 'handler' => 1];
unset($f['name']);
echo array_key_exists('name', $f) ? "flat=true\n" : "flat=false\n";

$n = ['k' => null];
echo array_key_exists('k', $n) ? "nullval=true\n" : "nullval=false\n";
