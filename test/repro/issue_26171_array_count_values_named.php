<?php
$r = new ReflectionFunction("array_count_values");
foreach ($r->getParameters() as $p) echo $p->getName(), "\n";
try { var_export(array_count_values(array: [1,1,2])); echo "\n"; }
catch (Throwable $e) { echo get_class($e), ":", $e->getMessage(), "\n"; }
