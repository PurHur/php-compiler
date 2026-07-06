<?php

declare(strict_types=1);

putenv('CONTENT_TYPE=application/x-www-form-urlencoded');
putenv('REQUEST_BODY=a=1&b=two');

[$post, $files] = request_parse_body();
var_export($post);
echo "\n";
var_export($files);
echo "\n";

