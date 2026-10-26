<?php

namespace App\Console\Commands;

use App\Wallets\WalletInput;
use App\Wallets\WalletService;
use Illuminate\Console\Command;
use Throwable;

class WalletImportCommand extends Command
{
    protected $signature = 'wallet:import {file : JSON array or CSV with chain,address,label,start_block,direction,min_amount columns} {--user=}';

    protected $description = 'Atomically import up to 100 wallets for one explicitly selected user';

    public function handle(WalletService $wallets): int
    {
        try {
            $file = $this->argument('file');
            if (! is_file($file) || ! is_readable($file) || filesize($file) > 1024 * 1024) {
                throw new \InvalidArgumentException('A readable local file of at most one MiB is required.');
            }
            $records = str_ends_with(strtolower($file), '.csv') ? $this->csv($file) : json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($records) || ! array_is_list($records)) {
                throw new \InvalidArgumentException('JSON imports require a list of records.');
            }
            $result = $wallets->importBatch(WalletInput::integer($this->option('user'), true), $records);
            $this->info('Processed '.$result->count().' wallet records. Existing private settings were preserved.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Wallet import failed. No partial batch was committed; check access and every input record.');

            return self::FAILURE;
        }
    }

    private function csv(string $file): array
    {
        $handle = fopen($file, 'r');
        try {
            $header = fgetcsv($handle, escape: '');
            if ($header === false || count(array_unique($header)) !== count($header)) {
                throw new \InvalidArgumentException('A CSV header with distinct column names is required.');
            }
            $records = [];
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== count($header) || count($records) >= 100) {
                    throw new \InvalidArgumentException('CSV shape or row limit is invalid.');
                }
                $record = array_combine($header, $row);
                if (($record['start_block'] ?? null) === '') {
                    unset($record['start_block']);
                }
                $records[] = $record;
            }

            return $records;
        } finally {
            fclose($handle);
        }
    }
}
