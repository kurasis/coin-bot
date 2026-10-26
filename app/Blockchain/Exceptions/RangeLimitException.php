<?php

declare(strict_types=1);

namespace App\Blockchain\Exceptions;

class RangeLimitException extends RpcException
{
    public function __construct()
    {
        parent::__construct('Blockchain query exceeded a configured range or result limit.');
    }
}
