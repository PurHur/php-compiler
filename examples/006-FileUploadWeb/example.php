<?php

declare(strict_types=1);

// Multipart upload demo — see examples/006-FileUploadWeb/README.md (#1999, #9226).
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><title>FileUploadWeb</title></head><body>';
echo '<h1>FileUploadWeb</h1>';

if ('POST' === $method && isset($_FILES['doc'])) {
    $name = (string) ($_FILES['doc']['name'] ?? '');
    $size = (int) ($_FILES['doc']['size'] ?? 0);
    echo '<p class="upload">Uploaded: ', htmlspecialchars($name), ' (', $size, " bytes)</p>\n";
} else {
    echo "<p>No upload yet. POST multipart with field <code>doc</code>.</p>\n";
}

echo '<form method="post" enctype="multipart/form-data">';
echo '<label>File <input type="file" name="doc"></label> ';
echo '<button type="submit">Upload</button></form>';
echo "</body></html>\n";
