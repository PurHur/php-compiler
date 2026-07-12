<?php
// stream_filter_remove(): default append on r+ keeps read filter after removing returned write filter (#18289)
$fp = fopen('php://memory', 'r+');
fwrite($fp, 'abc');
rewind($fp);
$filter = stream_filter_append($fp, 'string.toupper');
$removed = stream_filter_remove($filter);
rewind($fp);
$contents = stream_get_contents($fp);
var_export([$removed, $contents]);
echo "\n";
