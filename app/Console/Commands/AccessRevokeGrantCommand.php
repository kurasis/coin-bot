<?php

namespace App\Console\Commands;

use App\Access\AccessCommand;
use App\Access\AccessService;
use InvalidArgumentException;

class AccessRevokeGrantCommand extends AccessCommand
{
    protected $signature = 'access:revoke-grant {id : Access grant ID} {--operator=local-console}';

    protected $description = 'Revoke a user grant and remove only its pending-delivery authorizations';

    public function handle(AccessService $access): int
    {
        try {
            $access->revokeGrant($this->positiveInteger($this->argument('id'), 'ID'), $this->localOperator());
            $this->info('Access grant revoked.');

            return self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
