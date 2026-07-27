<?php
declare(strict_types=1);

putenv('PHPC_INI_TEST=fromenv');
var_export(parse_ini_string('a=${PHPC_INI_TEST}', false, INI_SCANNER_NORMAL));
echo "\n";
