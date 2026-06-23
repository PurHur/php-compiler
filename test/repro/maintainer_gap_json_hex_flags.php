<?php

declare(strict_types=1);

// Maintainer repro for #10956 — json_encode() JSON_HEX_* flags.

echo json_encode('<', JSON_HEX_TAG), "\n";
echo json_encode('<script>', JSON_HEX_TAG), "\n";

$s = "<&>\"'";
echo json_encode($s, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), "\n";
echo JSON_HEX_TAG | JSON_HEX_AMP, "\n";
