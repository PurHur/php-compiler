<?php

declare(strict_types=1);

// Compile-only (#11839): http_response_header after HTTP wrapper fetch.
@file_get_contents('http://example.com/');
echo isset($http_response_header) ? 'set' : 'unset', "\n";
