--TEST--
stream_bucket_* filter brigade API registered (VM, #4688, ext/standard/streamsfuncs.c)
--FILE--
<?php
echo (int) function_exists('stream_bucket_make_writeable');
echo "\n";
echo (int) function_exists('stream_bucket_append');
echo "\n";
echo (int) function_exists('stream_bucket_new');
echo "\n";
echo (int) function_exists('stream_bucket_prepend');
echo "\n";
class UppercaseFilter extends php_user_filter {
    public function filter($in, $out, &$consumed, $closing): int {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $bucket->data = strtoupper($bucket->data);
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}
stream_filter_register('uppercase4688', UppercaseFilter::class);
$fp = fopen('php://temp', 'w+');
stream_filter_append($fp, 'uppercase4688');
fwrite($fp, "ok\n");
rewind($fp);
echo stream_get_contents($fp);
--EXPECT--
1
1
1
1
OK
