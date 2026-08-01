--TEST--
stdlib ssh2_shell registration (#26663)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_shell')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_shell') ? '1' : '0';
echo defined('SSH2_TERM_UNIT_CHARS') ? '1' : '0';
echo defined('SSH2_TERM_UNIT_PIXELS') ? '1' : '0';
echo "\n";
?>
--EXPECT--
111
