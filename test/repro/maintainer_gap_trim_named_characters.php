<?php
// Issue #10027 — trim()/ltrim()/rtrim() PHP 8.4 named characters: parameter (php-src basic_functions.stub.php)

$s = '--hi--';
echo trim($s, characters: '-'), "\n";
echo ltrim($s, characters: '-'), "\n";
echo rtrim($s, characters: '-'), "\n";
