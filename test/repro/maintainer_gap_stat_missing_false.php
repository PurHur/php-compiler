<?php
declare(strict_types=1);

var_export(@stat('/no/such/phpc-maintainer-stat'));
echo "\n";
var_export(@lstat('/no/such/phpc-maintainer-stat'));
echo "\n";
