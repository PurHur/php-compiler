<?php
/**
 * Issue #22833 — sprintf()/vsprintf() custom pad %'<char> (php-src formatted_print.c).
 */
echo sprintf("%'*20s", 'x'), "\n";
echo sprintf("%'-10s", 'x'), "\n";
echo sprintf("%'*10d", 7), "\n";
echo vsprintf("%'*8s", ['x']), "\n";
echo sprintf("%1$'*10s", 'x'), "\n";
echo sprintf("%'#8s", 'ab'), "\n";
echo sprintf("%'*+10d", 7), "\n";
echo sprintf("%+'*10d", 7), "\n";
echo sprintf("%0'*8d", 7), "\n";
echo sprintf("%-'*10s", 'x'), "\n";
try {
    echo sprintf("%'", 'x'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
