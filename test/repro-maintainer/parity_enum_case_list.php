<?php
enum E {
    case A, B, C;
}
echo E::A->name, E::C->name, "\n";
