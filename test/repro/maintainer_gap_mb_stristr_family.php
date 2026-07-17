<?php
// Repro for #20006 — mb_stristr/mb_strrchr/mb_strripos vs Zend.
echo mb_stristr('Hello World', 'WORLD'), "\n";
echo mb_strrchr('Hello World', 'o'), "\n";
echo mb_strripos('Hello World', 'L'), "\n";
