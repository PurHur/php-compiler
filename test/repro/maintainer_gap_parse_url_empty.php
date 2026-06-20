<?php

declare(strict_types=1);

$empty = parse_url('');
var_dump($empty);
var_dump(parse_url('', PHP_URL_PATH));
var_dump(parse_url('?q=1'));
var_dump(parse_url('#frag'));
