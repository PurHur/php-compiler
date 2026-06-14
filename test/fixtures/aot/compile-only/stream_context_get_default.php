<?php
// AOT compile-only (#6367): stream_context_get_default / set_default JIT lowering.
stream_context_set_default(['http' => ['timeout' => 4]]);
$ctx = stream_context_get_default();
echo stream_context_get_options($ctx)['http']['timeout'], "\n";
