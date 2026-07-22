<?php
declare(strict_types=1);

echo sprintf('%.*s', 3, 'abcdef'), "\n";
echo vsprintf('%.*s', [3, 'abcdef']), "\n";
echo json_encode(sprintf('%*.*s', 6, 3, 'abcdef')), "\n";
echo sprintf('%.3s', 'abcdef'), "\n";
echo json_encode(sprintf('%.0s', 'abcdef')), "\n";
