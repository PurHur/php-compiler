<?php
/**
 * htmlspecialchars r1 free + Zend-shaped escapes (#36388).
 * @differential-repeat: 3
 */
echo htmlspecialchars('a<b&c>"\'x', ENT_QUOTES | ENT_SUBSTITUTE), "\n";
echo htmlspecialchars('&amp;', ENT_QUOTES, 'UTF-8', false), "\n";
echo htmlspecialchars("\xC3\x28", ENT_QUOTES | ENT_IGNORE), "\n";
