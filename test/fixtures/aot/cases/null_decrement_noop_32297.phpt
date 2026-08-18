--TEST--
AOT: --$null / $null-- stay NULL (#32297)
--FILE--
<?php
$null = null;
--$null;
var_dump($null);
$post = null;
$post--;
var_dump($post);
$inc = null;
$inc++;
var_dump($inc);
?>
--EXPECT--
NULL
NULL
int(1)
