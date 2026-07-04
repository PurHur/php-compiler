<?php
// Maintainer repro for #15817 — try/catch/else on PHP 8.4 forward profile.
try {
    echo "try\n";
} catch (Throwable) {
} else {
    echo "else\n";
}
