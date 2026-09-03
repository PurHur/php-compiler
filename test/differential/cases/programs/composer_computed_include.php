<?php
// #36382: computed require of a Composer-mapped autoload (VM + AOT match Zend)
// Bare AOT folds __DIR__.'/…' into a literal path and includes the fixture autoload
// (literal requires inside). Project builds still stub vendor/autoload.php via the file map.
$path = __DIR__ . '/../_fixtures/composer_mini/vendor/autoload.php';
require $path;
echo (new Pkg\Hello())->greet('world'), "\n";
