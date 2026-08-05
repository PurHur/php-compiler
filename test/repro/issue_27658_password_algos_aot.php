<?php
// #27658 — AOT password_algos() must match Zend/VM/JIT (ext/standard/password.c).
echo implode(',', password_algos()), "\n";
