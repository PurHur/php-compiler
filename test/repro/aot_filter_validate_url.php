<?php
// Issue #27206 — AOT filter_var(FILTER_VALIDATE_URL) must match Zend/VM for
// compile-time literals and dynamic (argv) strings. NestedJIT ?string was corrupt;
// bridge uses isValidInt + input __string__* (peer EMAIL #27068).
$litOk = filter_var('https://example.com/a', FILTER_VALIDATE_URL);
$litBad = filter_var('not a url', FILTER_VALIDATE_URL);
$dyn = $argv[1] ?? ('https://' . 'example.com/a');
$dynOk = filter_var($dyn, FILTER_VALIDATE_URL);
var_export($litOk);
echo "\n";
var_export($litBad);
echo "\n";
var_export($dynOk);
echo "\n";
