<?php
declare(strict_types=1);

$r = json_decode('{"a":1,"b":2}', null, 512, JSON_OBJECT_AS_ARRAY);
$ok = is_array($r);
echo $ok ? "ok\n" : "fail\n";

$r2 = json_decode('{"a":1}', false, 512, JSON_OBJECT_AS_ARRAY);
$ok2 = is_object($r2);
echo $ok2 ? "false-wins\n" : "false-fail\n";
