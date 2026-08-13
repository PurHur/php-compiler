<?php
// Repro #30813 — thin AOT nl2br must not SIGSEGV after c:main_before_php
echo nl2br("a\nb", false), "\n";
