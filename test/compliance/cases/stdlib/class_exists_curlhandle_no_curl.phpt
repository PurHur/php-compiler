--TEST--
stdlib CurlHandle classes registered with loaded ext/curl (#12117, #19728, #3325, #23953)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

echo 'curl_loaded=', (int) extension_loaded('curl'), "\n";
echo 'handle=', (int) class_exists('CurlHandle', false), "\n";
echo 'multi=', (int) class_exists('CurlMultiHandle', false), "\n";
echo 'share=', (int) class_exists('CurlShareHandle', false), "\n";
?>
--EXPECT--
curl_loaded=1
handle=1
multi=1
share=1
