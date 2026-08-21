<?php

declare(strict_types=1);

/**
 * AOT symlink() must create the link (not re-enter __phpc_jit_symlink via host \\symlink).
 * #33415 — peer unlink #33412 / mkdir #33402 / rmdir #33403.
 */
$t = sys_get_temp_dir().'/phpc_sy_'.getmypid();
$l = $t.'_link';
@unlink($t);
@unlink($l);
file_put_contents($t, 'x');
var_dump(symlink($t, $l));
var_dump(is_link($l));
@unlink($l);
@unlink($t);
