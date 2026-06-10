<?php
// Compile-only (#6018): dechex/decbin/decoct must lower enum-case TypeError guards for AOT.
enum E: int { case A = 10; }
dechex(E::A);
decbin(E::A);
decoct(E::A);
