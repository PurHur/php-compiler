<?php

declare(strict_types=1);

/**
 * Multipart file upload reference app (issue #1999).
 *
 * VM (GET only):
 *   ./phpc run examples/006-FileUploadWeb/example.php
 *
 * Serve (multipart POST):
 *   ./phpc serve 127.0.0.1:8080 examples/006-FileUploadWeb
 *   curl -s -F 'doc=@examples/006-FileUploadWeb/README.md' http://127.0.0.1:8080/example.php
 *
 * AOT: phpc build --project when FILE_UPLOAD_WEB_AOT_LINK_GATE=1 (#1999).
 */
$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';

header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><title>FileUploadWeb</title></head><body>';
echo '<h1>FileUploadWeb</h1>';

if ('POST' === $method && isset($_FILES['doc'])) {
    $name = isset($_FILES['doc']['name']) ? (string) $_FILES['doc']['name'] : '';
    $size = isset($_FILES['doc']['size']) ? (int) $_FILES['doc']['size'] : 0;
    echo '<p class="upload">Uploaded: ', htmlspecialchars($name), ' (', $size, " bytes)</p>\n";
} else {
    echo "<p>No upload yet. POST multipart with field <code>doc</code>.</p>\n";
}

echo '<form method="post" enctype="multipart/form-data">';
echo '<label>File <input type="file" name="doc"></label> ';
echo '<button type="submit">Upload</button></form>';
echo "</body></html>\n";
