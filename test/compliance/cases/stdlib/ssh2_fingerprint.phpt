--TEST--
stdlib ssh2_fingerprint constants + registration (#26575)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_fingerprint')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_fingerprint') ? '1' : '0';
echo defined('SSH2_FINGERPRINT_MD5') ? '1' : '0';
echo defined('SSH2_FINGERPRINT_SHA1') ? '1' : '0';
echo defined('SSH2_FINGERPRINT_HEX') ? '1' : '0';
echo defined('SSH2_FINGERPRINT_RAW') ? '1' : '0';
echo SSH2_FINGERPRINT_SHA1 === 1 ? '1' : '0';
echo SSH2_FINGERPRINT_RAW === 2 ? '1' : '0';
echo "\n";
?>
--EXPECT--
1111111
