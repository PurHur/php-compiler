<?php
// #36355 / #36221 — array_map('intval', $data["scores"]) must use the dim-fetch, not the outer json_decode array.
$raw = '{"scores":[90,70,100,80],"ok":true}';
$data = json_decode($raw, true);
$scores = array_map('intval', $data['scores']);
echo implode(',', $scores), "\n";

// Materialised dim must keep matching (control).
$tmp = $data['scores'];
echo implode(',', array_map('intval', $tmp)), "\n";

// Literal list through the same callback stays correct.
echo implode(',', array_map('intval', [90, 70, 100, 80])), "\n";
