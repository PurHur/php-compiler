<?php
$f = tmpfile();
var_dump(function_exists('stream_get_meta_data'));
var_dump(function_exists('stream_set_blocking'));
$meta = stream_get_meta_data($f);
var_dump(is_array($meta));
var_dump(
    isset($meta['wrapper_type'])
    && isset($meta['stream_type'])
    && isset($meta['seekable'])
);
var_dump(stream_set_blocking($f, true));
fclose($f);
