<?php
error_reporting(0);
parse_str(null, $o);
echo "parse_str-ok\n";
http_response_code(null);
echo "hrc-ok\n";
trigger_error(null);
echo "trigger-ok\n";
user_error(null);
echo "user-ok\n";
