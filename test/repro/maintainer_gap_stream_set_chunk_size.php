<?php
declare(strict_types=1);

$handle = fopen('php://memory', 'r+');
var_export(stream_set_chunk_size($handle, 8192));
