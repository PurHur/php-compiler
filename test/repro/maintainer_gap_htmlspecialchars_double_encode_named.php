<?php

// Maintainer gap / issue #10471 — htmlspecialchars() double_encode: named parameter.
echo htmlspecialchars('&amp;', double_encode: false), "\n";
echo htmlspecialchars('&amp;', double_encode: true), "\n";
