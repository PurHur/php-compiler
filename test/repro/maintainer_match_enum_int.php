<?php
enum E: int { case A = 1; }
echo match (1) {
    E::A => "match",
    default => "no",
};
