<?php

declare(strict_types=1);

/**
 * Minimal JSON API response (issue #270).
 *
 * VM:
 *   ./phpc run examples/004-ApiJson/example.php
 *
 * Serve:
 *   ./phpc serve 127.0.0.1:8080 examples/004-ApiJson
 *   curl -s -D - 'http://127.0.0.1:8080/example.php'
 */
header('Content-Type: application/json');
http_response_code(200);
echo json_encode(['ok' => true, 'service' => 'php-compiler']);
