<?php
/** AOT runtime probe #25749 — substr length null (Reflection method dispatch under AOT is separate). */
echo substr('abcdef', 1, null), "\n";
echo substr(string: 'abcdef', offset: 2), "\n";
echo substr('abcdef', 1, 2), "\n";
echo substr('abcdef', -2, null), "\n";
