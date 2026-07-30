--TEST--
stdlib curl_version() omits feature_list on 8.2 reference profile (#25357)
--FILE--
<?php
declare(strict_types=1);

$v = curl_version();
echo array_key_exists('feature_list', $v) ? "feature_list_present\n" : "feature_list_missing\n";
echo array_key_exists('features', $v) ? "features_present\n" : "features_missing\n";
echo array_key_exists('version_number', $v) ? "version_number_present\n" : "version_number_missing\n";
--EXPECT--
feature_list_missing
features_present
version_number_present
