<?php
// #33001: thin AOT mb_strlen after Type drops always-on utf8_strlen ABI shell.
$s = 'café';
echo mb_strlen($s, 'UTF-8'), "\n";
echo mb_strlen('abc', 'UTF-8'), "\n";
