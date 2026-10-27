<?php

namespace App\Console\Commands;

use App\Wallets\WalletInput;
use App\Wallets\WalletService;
use Illuminate\Console\Command;
use Throwable;

class WalletImportCommand extends Command
{
    private const MAX_FILE_BYTES = 1024 * 1024;

    protected $signature = 'wallet:import
        {file : TXT addresses, CSV records, or a JSON array}
        {--user= : Internal user ID}
        {--format=auto : auto, txt, csv, or json}
        {--chain= : TXT only: ethereum or tron}
        {--start-block= : TXT only: optional nonnegative block; default is next finalized block}
        {--direction= : TXT only: both, incoming, or outgoing (default both)}
        {--min-amount= : TXT only: minimum raw USDT units, six decimals (default 0)}';

    protected $description = 'Atomically import up to 100 wallets for one explicitly selected user';

    public function handle(WalletService $wallets): int
    {
        try {
            $file = $this->argument('file');
            if (! is_file($file) || ! is_readable($file)) {
                throw new \InvalidArgumentException('A readable local file of at most one MiB is required.');
            }
            // Bound the actual read, including files that grow after metadata checks.
            $contents = @file_get_contents($file, offset: 0, length: self::MAX_FILE_BYTES + 1);
            if ($contents === false || strlen($contents) > self::MAX_FILE_BYTES) {
                throw new \InvalidArgumentException('A readable local file of at most one MiB is required.');
            }
            if (str_starts_with($contents, "\xEF\xBB\xBF")) {
                $contents = substr($contents, 3);
            }
            $format = $this->option('format');
            if ($format === 'auto') {
                $format = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                    'txt' => 'txt',
                    'csv' => 'csv',
                    default => 'json', // Preserve extensionless JSON imports.
                };
            }
            if ($format !== 'txt') {
                foreach (['chain', 'start-block', 'direction', 'min-amount'] as $option) {
                    if ($this->option($option) !== null) {
                        throw new \InvalidArgumentException('Text defaults cannot override CSV or JSON record settings.');
                    }
                }
            }
            $records = match ($format) {
                'txt' => $this->text($contents),
                'csv' => $this->csv($contents),
                'json' => json_decode($contents, true, 32, JSON_THROW_ON_ERROR),
                default => throw new \InvalidArgumentException('Choose txt, csv, json, or auto format.'),
            };
            if (! is_array($records) || ! array_is_list($records)) {
                throw new \InvalidArgumentException('JSON imports require a list of records.');
            }
            $result = $wallets->importBatch(WalletInput::integer($this->option('user'), true), $records);
            $this->info('Processed '.$result->count().' wallet records. Existing private settings were preserved.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Wallet import failed. No partial batch was committed; check user access, file format, and all records. TXT requires --chain=tron or --chain=ethereum, with at most 100 addresses.');

            return self::FAILURE;
        }
    }

    private function text(string $contents): array
    {
        $chain = $this->option('chain');
        if (! in_array($chain, ['ethereum', 'tron'], true)
            || ! mb_check_encoding($contents, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $contents)) {
            throw new \InvalidArgumentException('TXT requires a valid chain and UTF-8 text without control characters.');
        }
        $defaults = [
            'chain' => $chain,
            'direction' => $this->option('direction') ?? 'both',
            'min_amount' => $this->option('min-amount') ?? '0',
        ];
        if ($this->option('start-block') !== null) {
            $defaults['start_block'] = WalletInput::integer($this->option('start-block'));
        }
        $records = [];
        foreach (preg_split('/\r\n|\n|\r/', $contents) as $line) {
            $address = trim($line);
            if ($address === '' || str_starts_with($address, '#')) {
                continue;
            }
            if (count($records) >= 100) {
                throw new \InvalidArgumentException('TXT imports accept at most 100 address lines.');
            }
            $records[] = $defaults + ['address' => $address];
        }

        return $records;
    }

    private function csv(string $contents): array
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \InvalidArgumentException('Cannot read the CSV contents.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || ! rewind($handle)) {
                throw new \InvalidArgumentException('Cannot read the CSV contents.');
            }
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
