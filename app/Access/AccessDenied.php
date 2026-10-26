<?php

namespace App\Access;

use RuntimeException;

class AccessDenied extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Access is unavailable.');
    }
}
