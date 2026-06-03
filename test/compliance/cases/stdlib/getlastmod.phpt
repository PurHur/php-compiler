--TEST--
stdlib getlastmod() returns executed script mtime (issue #5068)
--RUNFILE--
getlastmod_run.php
--EXPECT--
mtime
stable
