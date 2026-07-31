<?php
/**
 * Issue #25780 AOT smoke — headers_sent / flush run under native (Reflection is VM-verified).
 * get_headers / ob_list_handlers / http_response_code AOT gaps are pre-existing and out of scope.
 */
echo 'headers_sent=', headers_sent() ? '1' : '0', "\n";
flush();
echo "flush_ok\n";
