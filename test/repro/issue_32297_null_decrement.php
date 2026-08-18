<?php
/**
 * #32297 — --$null / $null-- must stay NULL (zend decrement_function IS_NULL).
 * AOT value-box decrement used __value__readLong and stored int(-1).
 */
$null = null;
--$null;
var_dump($null);
$post = null;
$post--;
var_dump($post);
$inc = null;
$inc++;
var_dump($inc);
