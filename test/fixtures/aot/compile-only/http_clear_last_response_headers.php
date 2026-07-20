<?php
// Compile-only (#7024): http_clear_last_response_headers lowering for AOT (#21172).
http_clear_last_response_headers();
var_export(http_get_last_response_headers());
echo "\n";
