<?php
// #36382: computed require of a Composer-mapped autoload (VM matches Zend)
// @differential-skip-aot: computed include needs project file-map allowlist (phpc build --project); bare AOT still requires literals (#36382)
$path = __DIR__ . '/../_fixtures/composer_mini/vendor/autoload.php';
require $path;
echo (new Pkg\Hello())->greet('world'), "\n";
