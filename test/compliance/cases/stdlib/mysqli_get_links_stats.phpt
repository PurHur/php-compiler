--TEST--
ext/mysqli mysqli_get_links_stats keys (#22183, ext/mysqli/mysqli_nonapi.c)
--FILE--
<?php
if (!function_exists('mysqli_get_links_stats')) {
    echo "missing\n";
    exit(1);
}
$s = mysqli_get_links_stats();
$keys = array_keys($s);
sort($keys);
echo 'keys:', implode(',', $keys), "\n";
echo 'total_int:', is_int($s['total']) ? 'yes' : 'no', "\n";
echo 'active_int:', is_int($s['active_plinks']) ? 'yes' : 'no', "\n";
echo 'cached_int:', is_int($s['cached_plinks']) ? 'yes' : 'no', "\n";
echo 'zero_idle:', ($s['total'] === 0 && $s['active_plinks'] === 0 && $s['cached_plinks'] === 0) ? 'yes' : 'no', "\n";
?>
--EXPECT--
keys:active_plinks,cached_plinks,total
total_int:yes
active_int:yes
cached_int:yes
zero_idle:yes
