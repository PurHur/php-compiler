<?php

putenv('PHP_COMPILER_LOCAL_ONLY_TEST=from_putenv');

$inherited = getenv('PATH', false);
$localFalse = getenv('PHP_COMPILER_LOCAL_ONLY_TEST', false);
$localTrue = getenv('PHP_COMPILER_LOCAL_ONLY_TEST', true);
$missingLocal = getenv('PATH', true);

echo $inherited === false ? 'no-path' : 'has-path', "\n";
echo $localFalse, "\n";
echo $localTrue, "\n";
echo $missingLocal === false ? 'false' : 'set', "\n";
