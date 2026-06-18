<?php
enum E: int { case A = 1; }
echo E::A::class, "\n";
enum U { case B; }
echo U::B::class, "\n";
