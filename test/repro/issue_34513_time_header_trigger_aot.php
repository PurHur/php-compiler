<?php
// #34513 — AOT after Type::initialize StringTime/EnvLocal/TriggerError/PendingHeaders lazy-link
echo time() > 0 ? "time_ok\n" : "time_no\n";
trigger_error('34513', E_USER_NOTICE);
echo "trig_ok\n";
header('X-Phpc-34513: 1');
$hl = headers_list();
echo is_array($hl) ? "hdr_ok\n" : "hdr_no\n";
