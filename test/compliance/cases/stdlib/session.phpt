--TEST--
Stdlib: session_start(), session_id(), session_name() VM (#64, #1183–#1184)
--FILE--
<?php
echo session_name(), "\n";
echo session_id(), "\n";
echo session_start() ? 'started' : 'fail', "\n";
echo strlen(session_id()) > 0 ? 'idok' : 'idno', "\n";
session_write_close();
echo session_start() ? 'again' : 'closed', "\n";
session_write_close();
echo session_name('APPSESS') === 'PHPSESSID' ? 'renamed' : 'namefail', "\n";
echo session_name(), "\n";
--EXPECT--
PHPSESSID

started
idok
again
renamed
APPSESS
