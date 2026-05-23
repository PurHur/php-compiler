<?php
declare(strict_types=1);
$data = "bootstrap"; $key = "secret";
echo hash("md5", $data); echo hash_hmac("sha256", $data, $key);
