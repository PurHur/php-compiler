<?php
// #36382: Composer-style vendor/autoload.php (literal classmap requires) under VM + AOT
require __DIR__ . '/../_fixtures/composer_mini/vendor/autoload.php';
echo (new Pkg\Hello())->greet('world'), '|', LegacyGreeter::say(), '|', Pkg\stamp(), "\n";
