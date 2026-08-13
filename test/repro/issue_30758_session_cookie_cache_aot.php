<?php
// Repro #30758 — session cookie/cache/save_path JIT/AOT (set before any output).
$ok = session_set_cookie_params(3600, '/app');
var_export($ok);
echo "\n";
$p = session_get_cookie_params();
echo $p['lifetime'], '|', $p['path'], '|', session_cache_limiter(), "\n";
echo session_save_path(), "\n";
