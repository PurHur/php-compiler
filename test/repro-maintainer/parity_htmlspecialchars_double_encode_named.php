<?php

declare(strict_types=1);

echo htmlspecialchars('<a>', ENT_QUOTES | ENT_HTML5, 'UTF-8', double_encode: false), "\n";
echo htmlentities('<a>', ENT_QUOTES | ENT_HTML5, 'UTF-8', double_encode: false), "\n";
