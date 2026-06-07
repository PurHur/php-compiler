<?php
var_export(in_array('string.rot13', stream_get_filters(), true));
echo "\n";
var_export(in_array('zlib.*', stream_get_filters(), true));
echo "\n";
