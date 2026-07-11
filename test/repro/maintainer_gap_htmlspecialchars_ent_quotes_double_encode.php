<?php
declare(strict_types=1);

echo htmlspecialchars('<>&"', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false), "\n";
echo htmlspecialchars('"', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false), "\n";
