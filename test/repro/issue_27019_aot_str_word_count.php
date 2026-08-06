<?php
/**
 * #27019 — AOT str_word_count must match Zend/VM (not silent 0).
 */
$s = 'hello world';
echo str_word_count('hello world'), PHP_EOL;
echo str_word_count($s), PHP_EOL;
echo str_word_count('hello world', 0), PHP_EOL;
