<?php

declare(strict_types=1);

var_export(enum_exists('StringTrimMode', false));
echo PHP_EOL;
echo trim('  x  '), PHP_EOL;
echo trim('  x  ', StringTrimMode::Both), PHP_EOL;
