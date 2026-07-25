<?php
/** Repro #23221 — get_extension_funcs('spl') must list 15 Zend procedurals. */
$spl = get_extension_funcs('spl');
echo is_array($spl) ? count($spl) : 'false';
echo "\n";
