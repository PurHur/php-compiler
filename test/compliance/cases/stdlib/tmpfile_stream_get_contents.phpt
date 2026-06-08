--TEST--
stdlib tmpfile() — stream_get_contents repro (issue #4929)
--RUNFILE--
tmpfile_stream_get_contents_run.php
--EXPECT--
string(4) "data"
