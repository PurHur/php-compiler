<?php
// #36355 — dim-fetch haystack into array_map must not bind prior FuncCall EXEC_RETURN.
$raw = '{"scores":[90,70,100,80],"ok":true}';
$data = json_decode($raw, true);
$scores = array_map('intval', $data['scores']);
echo implode(',', $scores), "\n";
echo count($scores), "\n";
