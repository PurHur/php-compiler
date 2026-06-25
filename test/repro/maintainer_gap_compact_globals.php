<?php
// Issue #11743 — compact() must include $GLOBALS-only variables.
$GLOBALS['phpc_compact_globals_probe'] = 42;
var_export(compact('phpc_compact_globals_probe'));
echo "\n";
