<?php

namespace App\Console\Commands;

use App\Access\AccessCommand;
use App\Access\AccessService;
use InvalidArgumentException;

class AccessExpireKeyCommand extends AccessCommand
{
    protected $signature = 'access:expire-key {id : Access key ID} {--operator=local-console}';

    protected $description = 'Expire an activation key without changing an already activated grant';

    public function handle(AccessService $access): int
    {
        try {
            $access->expireKey($this->positiveInteger($this->argument('id'), 'ID'), $this->localOperator());
            $this->info('Access key expired.');

            return self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
