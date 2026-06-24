--TEST--
stdlib stream_is_local() accepts string paths (#11358, ext/standard/streams.c)
--FILE--
<?php
echo stream_is_local('php://memory') ? "memory=true\n" : "memory=false\n";
echo stream_is_local(__FILE__) ? "file=true\n" : "file=false\n";
--EXPECT--
memory=true
file=true
