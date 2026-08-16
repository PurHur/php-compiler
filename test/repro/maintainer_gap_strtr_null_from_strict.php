<?php
declare(strict_types=1);
// Maintainer gap probe: strtr three-arg null $from under strict_types (re-#30235).
// Zend: TypeError … must be of type array|string, null given
// VM (2026-08-16): TypeError … must be of type string, null given
var_export(strtr('a', null, 'b'));
echo "\n";
