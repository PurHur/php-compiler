--TEST--
JIT: session cookie params / cache_limiter / save_path (#30758)
--FILE--
<?php
$ok = session_set_cookie_params(3600, '/app');
var_export($ok);
echo "\n";
$p = session_get_cookie_params();
echo $p['lifetime'], '|', $p['path'], '|', session_cache_limiter(), "\n";
echo session_save_path() !== '' ? 'save_path_nonempty' : 'save_path_empty', "\n";
echo "ok\n";
?>
--EXPECT--
true
3600|/app|nocache
save_path_nonempty
ok
