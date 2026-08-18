--TEST--
posix_getlogin()/posix_ttyname()/posix_isatty() registered (issue #6504)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('posix_getlogin') ? 'getlogin_yes' : 'getlogin_no', "\n";
echo function_exists('posix_ttyname') ? 'ttyname_yes' : 'ttyname_no', "\n";
echo function_exists('posix_isatty') ? 'isatty_yes' : 'isatty_no', "\n";

$login = @posix_getlogin();
var_export(is_string($login) || false === $login);
echo "\n";

$isatty = posix_isatty(0);
var_export(is_bool($isatty));
echo "\n";

$rf = new ReflectionFunction('posix_isatty');
$params = $rf->getParameters();
echo $params[0]->getName(), "\n";
var_export($params[0]->hasType());
echo "\n";

$named = posix_isatty(file_descriptor: 0);
var_export(is_bool($named));
echo "\n";

if (\defined('STDOUT')) {
    $namedResource = posix_isatty(file_descriptor: STDOUT);
    var_export(is_bool($namedResource));
    echo "\n";
}

$tty = @posix_ttyname(0);
var_export(false === $tty || (is_string($tty) && str_starts_with($tty, '/dev/')));
echo "\n";

// Invalid fd: always non-TTY (portable across CI hosts with/without a controlling terminal).
var_export(posix_isatty(99999));
echo "\n";
var_export(@posix_ttyname(99999));
echo "\n";
--EXPECT--
getlogin_yes
ttyname_yes
isatty_yes
true
true
file_descriptor
false
true
true
true
false
false
