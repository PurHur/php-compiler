<?php

declare(strict_types=1);

foreach (['resourcebundle_create', 'resourcebundle_count', 'resourcebundle_get_error_code', 'resourcebundle_get_error_message'] as $f) {
    echo $f . '=' . (function_exists($f) ? 'yes' : 'no') . "\n";
}
$rb = resourcebundle_create('en', null);
echo 'oop=' . $rb->getErrorCode() . '/' . $rb->getErrorMessage() . "\n";
echo 'proc=' . resourcebundle_get_error_code($rb) . '/' . resourcebundle_get_error_message($rb) . "\n";
echo 'match=' . (int) ($rb->getErrorCode() === resourcebundle_get_error_code($rb)
    && $rb->getErrorMessage() === resourcebundle_get_error_message($rb)) . "\n";
