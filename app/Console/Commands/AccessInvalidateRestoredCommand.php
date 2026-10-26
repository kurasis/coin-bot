<?php

namespace App\Console\Commands;

use App\Access\AccessCommand;
use App\Access\AccessService;
use InvalidArgumentException;

class AccessInvalidateRestoredCommand extends AccessCommand
{
    protected $signature = 'access:invalidate-restored {--force : Explicitly invalidate all restored access} {--operator=local-console}';

    protected $description = 'Fail closed after backup restore by invalidating all restored keys and grants';

    public function handle(AccessService $access): int
    {
        if (! $this->option('force')) {
            $this->error('Use --force while enrollment and delivery workers are stopped.');

            return self::FAILURE;
        }

        try {
            $access->invalidateRestored($this->localOperator());
            $this->info('All restored access invalidated. Fresh activation is required.');

            return self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
