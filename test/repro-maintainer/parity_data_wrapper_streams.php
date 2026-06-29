<?php

declare(strict_types=1);

$uri = 'data://text/plain,hello';

var_dump(fopen($uri, 'r') !== false);
var_dump(file_get_contents($uri));
var_dump(copy('data://text/plain,x', 'php://memory'));
