<?php
$v = curl_version();
echo "feature_list=", array_key_exists("feature_list", $v) ? "yes" : "MISSING", "\n";
if (array_key_exists("feature_list", $v)) {
    $fl = $v['feature_list'];
    echo "is_array=", is_array($fl) ? "yes" : "no", "\n";
    foreach (["ipv6","ssl","libz","http2","http3","hsts","altsvc","brotli","zstd"] as $k) {
        echo $k, "=", array_key_exists($k, $fl) ? ($fl[$k] ? "true" : "false") : "MISSING", "\n";
    }
}
foreach (["CURL_VERSION_HTTP2","CURL_VERSION_HTTP3","CURL_VERSION_HSTS","CURL_VERSION_SSL","CURL_VERSION_IPV6"] as $c) {
    echo $c, "=", defined($c) ? constant($c) : "MISSING", "\n";
}
