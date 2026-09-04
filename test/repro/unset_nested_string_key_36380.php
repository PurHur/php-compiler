<?php
/**
 * Nested unset($a[0]['name']) must remove the key from the live element (#36380).
 *
 * php-src: Zend/zend_compile.c ZEND_FETCH_DIM_W + ZEND_UNSET_DIM
 * (Parsedown::li strips name=p this way).
 *
 * Prefer isset()/array_keys over array_key_exists here — AOT array_key_exists can
 * still report a removed string key after nested unset (follow-up).
 */
$Elements = [
    [
        'name' => 'p',
        'handler' => ['function' => 'lineElements', 'argument' => 'one', 'destination' => 'elements'],
    ],
];
unset($Elements[0]['name']);
echo isset($Elements[0]['name']) ? "BAD\n" : "OK\n";
echo 'keys=' . implode(',', array_keys($Elements[0])) . "\n";

// Three-level (Parsedown sanitiseElement href strip shape).
$Inline = [
    'element' => [
        'attributes' => ['href' => 'http://x', 'alt' => 'y'],
    ],
];
unset($Inline['element']['attributes']['href']);
echo isset($Inline['element']['attributes']['href']) ? "BAD3\n" : "OK3\n";
