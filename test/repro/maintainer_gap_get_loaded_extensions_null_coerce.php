<?php

$ext = get_loaded_extensions(null);
$count = count($ext);
$hasStandard = in_array('standard', $ext, true) ? 'yes' : 'no';
echo "count={$count} has_standard={$hasStandard}\n";
