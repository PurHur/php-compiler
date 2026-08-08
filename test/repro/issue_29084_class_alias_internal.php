<?php
declare(strict_types=1);

// Issue #29084 — Zend allows class_alias of internal classes; duplicate name warns+false.
var_export(class_alias('stdClass', 'FooAlias29084'));
echo "\n";
var_export((new FooAlias29084()) instanceof stdClass);
echo "\n";
var_export(class_alias('Exception', 'E29084'));
echo "\n";
var_export((new E29084('x')) instanceof Exception);
echo "\n";
var_export(class_alias('stdClass', 'stdClass'));
echo "\n";
