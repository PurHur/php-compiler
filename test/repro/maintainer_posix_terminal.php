<?php
foreach (['posix_getlogin', 'posix_ttyname', 'posix_isatty'] as $f) {
    echo $f, ': ', function_exists($f) ? 'yes' : 'no', "\n";
}
$login = @posix_getlogin();
echo is_string($login) || false === $login ? 'login-ok' : 'login-bad', "\n";
echo 'stdin_isatty=', posix_isatty(0) ? '1' : '0', "\n";
$tty = @posix_ttyname(0);
echo false === $tty || (is_string($tty) && '' !== $tty) ? 'tty-ok' : 'tty-bad', "\n";
echo 'bogus_isatty=', posix_isatty(99999) ? '1' : '0', "\n";
echo 'bogus_tty=', var_export(@posix_ttyname(99999), true), "\n";
