<?php
// #19627 — urlencoded request_parse_body() user-script AOT (pair with issue_5965 multipart).
// Repro must stay green without LC_CTYPE in the process environ (heap layout used to abort).
putenv('CONTENT_TYPE=application/x-www-form-urlencoded');
putenv('REQUEST_BODY=a=1&b=2');
$pair = request_parse_body();
echo $pair[0]['a'], ',', $pair[0]['b'], "\n";
