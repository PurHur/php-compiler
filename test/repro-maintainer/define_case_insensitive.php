<?php

declare(strict_types=1);

echo define('MyConst', 42, true) ? '1' : '0', "\n";
echo defined('myconst') ? '1' : '0', "\n";
echo constant('MYCONST'), "\n";

echo define('CaseSens', 7) ? '1' : '0', "\n";
echo defined('casesens') ? '1' : '0', "\n";
echo defined('CaseSens') ? '1' : '0', "\n";
