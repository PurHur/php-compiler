<?php

$a = ['k' => 1];
// Ternary forces a CFG split after isset (same stdout as var_export(isset(...))).
echo isset($a['k']) ? 'true' : 'false';
