<?php
// Issue #4555: realpath('.') must resolve to absolute cwd, not ".".

var_export(realpath('.'));
echo "\n";
var_export(realpath('/tmp/no-such-entry-phpc-dirname-levels'));
echo "\n";
var_export(dirname('/a/b/c', 2));
echo "\n";
