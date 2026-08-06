--TEST--
stdlib imap_timeout + IMAP_*TIMEOUT constants (#27680)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27680)');
}
?>
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('imap') ? '1' : '0';
echo function_exists('imap_timeout') ? '1' : '0';
echo defined('IMAP_OPENTIMEOUT') ? '1' : '0';
echo defined('IMAP_READTIMEOUT') ? '1' : '0';
echo defined('IMAP_WRITETIMEOUT') ? '1' : '0';
echo defined('IMAP_CLOSETIMEOUT') ? '1' : '0';
echo "\n";

echo IMAP_OPENTIMEOUT, ',', IMAP_READTIMEOUT, ',', IMAP_WRITETIMEOUT, ',', IMAP_CLOSETIMEOUT, "\n";

$rf = new ReflectionFunction('imap_timeout');
$params = $rf->getParameters();
echo $params[0]->getName(), '/', $params[1]->getName(), "\n";
echo $params[1]->isOptional() ? 'opt' : 'req', "\n";
echo $params[1]->isDefaultValueAvailable() ? (string)$params[1]->getDefaultValue() : '-', "\n";
echo $rf->hasReturnType() ? (string)$rf->getReturnType() : '-', "\n";

$open = imap_timeout(IMAP_OPENTIMEOUT);
echo is_int($open) ? 'get_int' : 'get_bad', "\n";
echo (true === imap_timeout(IMAP_OPENTIMEOUT, 42)) ? "set_ok\n" : "set_bad\n";
echo imap_timeout(IMAP_OPENTIMEOUT), "\n";
echo (true === imap_timeout(timeout_type: IMAP_READTIMEOUT, timeout: 17)) ? "named_ok\n" : "named_bad\n";
echo imap_timeout(IMAP_READTIMEOUT), "\n";
echo (false === imap_timeout(99)) ? "bad_type\n" : "bad_type_fail\n";
echo (false === imap_timeout(IMAP_OPENTIMEOUT, -5)) ? "neg_ok\n" : "neg_fail\n";
?>
--EXPECT--
111111
1,2,3,4
timeout_type/timeout
opt
-1
int|bool
get_int
set_ok
42
named_ok
17
bad_type
neg_ok
