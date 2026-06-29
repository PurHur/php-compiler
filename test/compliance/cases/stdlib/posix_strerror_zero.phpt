--TEST--
stdlib posix_strerror(0) — Success (ext/posix/posix.c, #13402)
--FILE--
<?php
echo posix_strerror(0), "\n";
--EXPECT--
Success
