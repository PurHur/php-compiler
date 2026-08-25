<?php
/** Repro: AOT mb_stristr/stripos Unicode case miss (NestedJIT ASCII-only lower). */
$s = 'aÉï';
var_export(mb_stristr($s, 'é'));
echo PHP_EOL;
var_export(mb_stripos($s, 'é'));
echo PHP_EOL;
var_export(mb_strripos($s, 'é'));
echo PHP_EOL;
var_export(mb_strrichr($s, 'é'));
echo PHP_EOL;
