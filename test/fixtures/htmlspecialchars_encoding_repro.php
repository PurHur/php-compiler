<?php

// ISO-8859-1 byte for umlaut-a (issue #3784 repro).
echo htmlspecialchars("\xE4", ENT_QUOTES, 'ISO-8859-1'), "\n";
echo htmlspecialchars('<x>', ENT_QUOTES, 'Windows-1252'), "\n";
