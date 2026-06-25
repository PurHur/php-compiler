<?php

declare(strict_types=1);

$s = '<>&"';
echo htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false), "\n";
echo htmlentities($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false), "\n";
