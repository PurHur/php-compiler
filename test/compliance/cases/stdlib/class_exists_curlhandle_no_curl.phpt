--TEST--
stdlib CurlHandle classes withheld without ext/curl — no phantom class_exists (#12117, ext/curl/interface.c)
--FILE--
<?php
declare(strict_types=1);

echo 'curl_loaded=', (int) extension_loaded('curl'), "\n";
echo 'handle=', (int) class_exists('CurlHandle', false), "\n";
echo 'multi=', (int) class_exists('CurlMultiHandle', false), "\n";
echo 'share=', (int) class_exists('CurlShareHandle', false), "\n";
?>
--EXPECT--
curl_loaded=0
handle=0
multi=0
share=0
