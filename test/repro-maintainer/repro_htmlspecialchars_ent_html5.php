<?php
echo htmlspecialchars("'", ENT_QUOTES | ENT_HTML5), "\n";
echo htmlspecialchars("'", ENT_QUOTES), "\n";
echo htmlentities("'", ENT_QUOTES | ENT_HTML5), "\n";
