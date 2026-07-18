--TEST--
curl_share_init_persistent withheld on PROFILE=8.4 (#20530 phantom)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo 'fn=', function_exists('curl_share_init_persistent') ? '1' : '0', "\n";
echo 'class=', class_exists('CurlSharePersistentHandle', false) ? '1' : '0', "\n";
echo 'share=', function_exists('curl_share_init') ? '1' : '0', "\n";
?>
--EXPECT--
fn=0
class=0
share=1
