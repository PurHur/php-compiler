<?php

declare(strict_types=1);

class UppercaseFilter extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $bucket->data = strtoupper($bucket->data);
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}

stream_filter_register('uppercase', UppercaseFilter::class);
$fp = fopen('php://temp', 'w+');
stream_filter_append($fp, 'uppercase');
fwrite($fp, "hello\n");
rewind($fp);
echo stream_get_contents($fp);
