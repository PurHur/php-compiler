<?php
declare(strict_types=1);

$s = '<';
echo json_encode($s, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), "\n";
var_export(JSON_HEX_TAG | JSON_HEX_AMP);
echo "\n";
