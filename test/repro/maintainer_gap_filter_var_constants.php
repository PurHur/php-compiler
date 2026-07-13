<?php

declare(strict_types=1);

var_dump(filter_var('42', FILTER_VALIDATE_INT));
var_dump(filter_var('not-int', FILTER_VALIDATE_INT));
var_dump(filter_var('1.2', FILTER_VALIDATE_FLOAT));
var_dump(filter_var('x', FILTER_SANITIZE_STRING));

