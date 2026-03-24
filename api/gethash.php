<?php
$hash = password_hash('boomeritemsbaran!', PASSWORD_BCRYPT, ['cost' => 12]);
echo $hash;
