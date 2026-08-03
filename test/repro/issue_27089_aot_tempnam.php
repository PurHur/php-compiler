<?php
// Issue #27089: AOT tempnam() — NestedJIT must not orphan the caller insert block.
$t = tempnam(sys_get_temp_dir(), 'pc');
echo is_string($t) ? 'ok' : 'no', PHP_EOL;
if (is_string($t)) {
    @unlink($t);
}
