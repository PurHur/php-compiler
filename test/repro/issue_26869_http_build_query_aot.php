<?php
// AOT: http_build_query must link __compiler_http_build_query (#26869).
echo http_build_query(['a' => 1, 'b' => [2, 3]]), "\n";
