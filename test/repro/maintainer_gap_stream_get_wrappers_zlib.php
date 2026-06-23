<?php
$wrappers = stream_get_wrappers();
var_export(in_array('compress.zlib', $wrappers, true));
echo "\n";
var_export(in_array('zlib', $wrappers, true));
echo "\n";
