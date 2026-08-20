<?php

declare(strict_types=1);

/** #32063 follow-up — htmlentities() AOT must accept UTF-8 encoding arg like htmlspecialchars. */
echo htmlentities('<tag>', ENT_QUOTES, 'UTF-8'), "\n";
echo htmlentities('<b>"\'</b>', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), "\n";
