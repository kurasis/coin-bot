<?php

namespace App\Console\Commands;

use App\Access\AccessCommand;
use App\Access\AccessService;
use InvalidArgumentException;

class AccessRevokeKeyCommand extends AccessCommand
{
    protected $signature = 'access:revoke-key {id : Access key ID} {--operator=local-console}';

    protected $description = 'Revoke an activation key and its associated grant, if already redeemed';

    public function handle(AccessService $access): int
    {
        try {
            $access->revokeKey($this->positiveInteger($this->argument('id'), 'ID'), $this->localOperator());
            $this->info('Access key revoked.');

            return self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
