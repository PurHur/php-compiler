<?php

declare(strict_types=1);

$h = fopen('php://memory', 'r+');
var_export(stream_is_local($h));
fclose($h);

$fp = fopen(__FILE__, 'r');
var_export(stream_is_local($fp));
fclose($fp);
