<?php

declare(strict_types=1);

echo timezone_name_from_abbr('EST', -18000, 0), "\n";
var_export(timezone_name_from_abbr('NOTREAL', -1, -1));
echo "\n";
