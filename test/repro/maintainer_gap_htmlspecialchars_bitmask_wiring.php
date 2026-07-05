<?php
declare(strict_types=1);
$s = '<>&"';
htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
