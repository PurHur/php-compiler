--TEST--
phpcredits(CREDITS_ALL) DOM row — forward profile includes Nora Dossche (#16740, ext/standard/credits.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
ob_start();
phpcredits(CREDITS_ALL);
$out = ob_get_clean();
echo str_contains($out, 'Nora Dossche') ? 'has_nora' : 'no_nora', "\n";
echo str_contains($out, 'DOM => Christian Stocker, Rob Richards, Marcus Boerger, Nora Dossche') ? 'dom_ok' : 'dom_bad', "\n";
--EXPECT--
has_nora
dom_ok
