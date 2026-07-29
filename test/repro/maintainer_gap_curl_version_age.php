<?php
// Issue 24463 curl version shape
$v = curl_version();
foreach (["version_number","age","features","feature_list","ssl_version_number","version","host","ssl_version","libz_version","protocols","ares","ares_num","libidn","iconv_ver_num","libssh_version","brotli_ver_num","brotli_version"] as $k) {
  echo $k, "=", array_key_exists($k, $v) ? "1" : "0", "\n";
}
$age = $v["age"];
echo "age_val=", $age, "\n";
$protocols = $v["protocols"];
echo "protocols_is_array=", is_array($protocols) ? "1" : "0", "\n";
echo "protocols_count=", is_array($protocols) ? count($protocols) : "bad", "\n";
if (is_array($protocols)) {
  $first = $protocols[0] ?? "missing";
  echo "protocols0=", $first, "\n";
}
echo "CURLVERSION_NOW=", defined("CURLVERSION_NOW") ? (string) CURLVERSION_NOW : "UNDEF", "\n";
echo "age_matches_now=", (defined("CURLVERSION_NOW") && CURLVERSION_NOW === $age) ? "1" : "0", "\n";
