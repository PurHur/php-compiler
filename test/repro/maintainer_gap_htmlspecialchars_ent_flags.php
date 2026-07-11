<?php
declare(strict_types=1);

echo htmlspecialchars("<a&'>", ENT_QUOTES), "\n";
echo htmlentities("<a&'>", ENT_COMPAT), "\n";
