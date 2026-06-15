<?php

$ctx = stream_context_create(['http' => ['timeout' => 5]]);
var_export(is_resource($ctx));
echo "\n";
var_export(get_resource_type($ctx));
echo "\n";
