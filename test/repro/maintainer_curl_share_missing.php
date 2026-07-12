<?php
declare(strict_types=1);

foreach (['curl_share_init', 'curl_share_setopt', 'curl_share_close'] as $f) {
    echo $f, ': ', function_exists($f) ? 'yes' : 'no', "\n";
}

$share = curl_share_init();
curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
curl_share_close($share);
echo "ok\n";
