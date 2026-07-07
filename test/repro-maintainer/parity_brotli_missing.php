<?php
$data = 'hello';
$compressed = brotli_compress($data);
var_export($compressed);
echo "\n";
var_export(brotli_uncompress($compressed));
