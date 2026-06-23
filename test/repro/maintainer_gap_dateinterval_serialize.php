<?php
$iv = new DateInterval('P1DT2H');
$blob = serialize($iv);
var_export(substr_count($blob, 'from_string') > 0);
echo "\n";
var_export(preg_match('/O:12:"DateInterval":10:/', $blob) === 1);
echo "\n";
$round = unserialize($blob);
var_export([$round->d, $round->h]);
echo "\n";
