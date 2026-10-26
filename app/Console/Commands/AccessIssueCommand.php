<?php

namespace App\Console\Commands;

use App\Access\AccessCommand;
use App\Access\AccessService;
use InvalidArgumentException;

class AccessIssueCommand extends AccessCommand
{
    protected $signature = 'access:issue {--hours= : Activation-key lifetime in hours} {--grant-hours= : Grant lifetime measured from issuance} {--operator=local-console : Audit identity of the trusted local operator}';

    protected $description = 'Issue an individual activation key; the raw key is shown once';

    public function handle(AccessService $access): int
    {
        try {
            $expiresAt = $this->option('hours') === null ? null : now()->addHours($this->positiveInteger($this->option('hours'), 'Hours'));
            $grantExpiresAt = $this->option('grant-hours') === null ? null : now()->addHours($this->positiveInteger($this->option('grant-hours'), 'Grant hours'));
            $issued = $access->issue($expiresAt, $grantExpiresAt, $this->localOperator());
            $this->line($issued->token);
            $this->info('Key ID: '.$issued->id.'; expires: '.$issued->expiresAt->toIso8601String());
            $this->comment('Copy the key securely now. It cannot be retrieved again.');

            return self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
