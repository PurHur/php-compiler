--TEST--
stdlib CURLFile/CURLStringFile withheld without ext/curl (#19671)
--FILE--
<?php
echo 'curl_loaded=', (int) extension_loaded('curl'), "\n";
echo 'curl_init=', (int) function_exists('curl_init'), "\n";
echo 'curl_file_create=', (int) function_exists('curl_file_create'), "\n";
echo 'CURLFile=', (int) class_exists('CURLFile', false), "\n";
echo 'CURLStringFile=', (int) class_exists('CURLStringFile', false), "\n";
try {
    new CURLFile('/tmp/x');
    echo "curlfile_no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
try {
    new CURLStringFile('x', 'y');
    echo "curlstringfile_no_throw\n";
} catch (Throwable $e) {
    echo 'stringfile=', get_class($e), "\n";
}
?>
--EXPECT--
curl_loaded=0
curl_init=0
curl_file_create=0
CURLFile=0
CURLStringFile=0
Error
stringfile=Error
