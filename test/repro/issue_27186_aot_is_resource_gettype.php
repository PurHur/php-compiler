<?php

declare(strict_types=1);

/**
 * Repro #27186 — AOT gettype()/is_resource() on fopen streams must match Zend.
 *
 * Thin standalone AOT previously reported integer/false because __compiler_is_resource
 * NestedJIT'd StreamLifecycleJitHelper while fopen registered in LLVM phpc_stream_handles.
 */
$f = fopen('php://memory', 'r+');
echo 'gettype='.gettype($f)."\n";
echo 'is_resource='.(is_resource($f) ? 'Y' : 'N')."\n";

$p = sys_get_temp_dir().'/phpc_27186_'.getmypid().'.txt';
file_put_contents($p, 'x');
$g = fopen($p, 'r');
echo 'file_gettype='.gettype($g)."\n";
echo 'file_is_resource='.(is_resource($g) ? 'Y' : 'N')."\n";
fclose($g);
unlink($p);
