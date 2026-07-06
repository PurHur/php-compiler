--TEST--
phpcredits(CREDITS_ALL) DOM row — 8.2 reference profile omits forward contributors (#16740, ext/standard/credits.c)
--FILE--
<?php
ob_start();
phpcredits(CREDITS_ALL);
$out = ob_get_clean();
echo str_contains($out, 'Nora Dossche') ? 'has_nora' : 'no_nora', "\n";
echo str_contains($out, 'DOM => Christian Stocker, Rob Richards, Marcus Boerger') ? 'dom_ok' : 'dom_bad', "\n";
echo str_contains($out, 'uri =>') ? 'has_uri' : 'no_uri', "\n";
--EXPECT--
no_nora
dom_ok
no_uri
