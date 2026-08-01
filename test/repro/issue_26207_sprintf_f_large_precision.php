<?php
declare(strict_types=1);

// #26207 — %f / %.Nf on large floats must match Zend digit/precision counts
echo sprintf('%f', 1e20), PHP_EOL;
echo sprintf('%.0f', 1e20), PHP_EOL;
echo sprintf('%.2f', 1e20), PHP_EOL;
echo sprintf('%.6f', 1e20), PHP_EOL;
echo sprintf('%f', 1.5), PHP_EOL;
