<?php
/**
 * #23006 — Dom\TokenList indexed dimension read / isset (ext/dom/token_list.c).
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_23006_tokenlist_dimension.php
 */
$html = Dom\HTMLDocument::createFromString('<!doctype html><p class="a b c">x</p>');
$tl = $html->querySelector('p')->classList;
echo $tl[0], "\n";
var_export(isset($tl[0]));
echo "\n";
var_export(isset($tl[9]));
echo "\n";
var_export($tl[9]);
echo "\n";
