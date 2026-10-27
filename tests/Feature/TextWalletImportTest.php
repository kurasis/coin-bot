<?php

namespace Tests\Feature;

use App\Access\AccessService;
use App\Blockchain\AddressCodec;
use App\Wallets\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TextWalletImportTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_plain_text_import_accepts_one_hundred_ethereum_addresses_atomically(): void
    {
        $user = $this->user('1001');
        $addresses = array_map($this->address(...), range(1, 100));
        $file = $this->file(implode("\n", $addresses)."\n");

        $this->assertSame(0, $this->import($file, $user, ['--chain' => 'ethereum', '--start-block' => '100']));

        $this->assertDatabaseCount('wallets', 100);
        $this->assertDatabaseCount('user_wallets', 100);
        $this->assertDatabaseCount('wallet_subscriptions', 100);
        $cursor = DB::table('scan_cursors')->where('chain', 'ethereum')->where('role', 'primary')->sole();
        $this->assertSame(99, (int) $cursor->last_block);
        $this->assertSame(100, DB::table('watch_set_wallets')->where('watch_set_id', $cursor->watch_set_id)->count());
        $this->assertSame($addresses, DB::table('wallets')->orderBy('id')->pluck('address')->all());
        Http::assertNothingSent();
    }

    public function test_tron_text_import_accepts_valid_base58_addresses_and_subscription_defaults(): void
    {
        $user = $this->user('1001');
        $first = AddressCodec::normalize('tron', '41'.str_repeat('1', 40));
        $second = AddressCodec::normalize('tron', '41'.str_repeat('2', 40));
        $file = $this->file($first."\n".$second);

        $this->assertSame(0, $this->import($file, $user, [
            '--chain' => 'tron', '--start-block' => '100', '--direction' => 'incoming', '--min-amount' => '2500000',
        ]));

        $this->assertDatabaseHas('wallets', ['chain' => 'tron', 'address' => $first]);
        $this->assertDatabaseHas('wallets', ['chain' => 'tron', 'address' => $second]);
        $this->assertSame(['incoming'], DB::table('wallet_subscriptions')->pluck('direction')->unique()->all());
        $this->assertSame(['2500000'], DB::table('wallet_subscriptions')->pluck('min_amount')->unique()->all());
        $this->assertDatabaseCount('user_wallets', 2);
        Http::assertNothingSent();
    }

    public function test_windows_text_files_accept_bom_blank_lines_comments_and_uppercase_extension(): void
    {
        $user = $this->user('1001');
        $file = $this->file("\xEF\xBB\xBF# Treasury wallets\r\n\r\n  ".$this->address(1)."  \r\n  # Another wallet\r\n\t".$this->address(2)."\t\r\n", 'TXT');

        $this->assertSame(0, $this->import($file, $user, ['--chain' => 'ethereum', '--start-block' => '100']));

        $this->assertDatabaseCount('wallets', 2);
        $this->assertDatabaseHas('wallets', ['address' => $this->address(1)]);
        $this->assertDatabaseHas('wallets', ['address' => $this->address(2)]);
        Http::assertNothingSent();
    }

    public function test_invalid_later_line_rolls_back_the_whole_text_import_and_is_not_printed(): void
    {
        $user = $this->user('1001');
        $invalid = 'private-invalid-input-'.$this->address(2);
        $file = $this->file($this->address(1)."\n".$invalid."\n");

        $this->assertSame(1, $this->import($file, $user, ['--chain' => 'ethereum', '--start-block' => '100']));

        $this->assertNoWalletWrites();
        $this->assertStringNotContainsString($invalid, Artisan::output());
        $this->assertStringNotContainsString($this->address(1), Artisan::output());
        Http::assertNothingSent();
    }

    public function test_a_tron_address_in_an_ethereum_list_rejects_the_entire_batch(): void
    {
        $user = $this->user('1001');
        $tron = AddressCodec::normalize('tron', '41'.str_repeat('1', 40));
        $file = $this->file($this->address(1)."\n".$tron."\n");

        $this->assertSame(1, $this->import($file, $user, ['--chain' => 'ethereum', '--start-block' => '100']));

        $this->assertNoWalletWrites();
        Http::assertNothingSent();
    }

    public function test_repeated_addresses_preserve_labels_filters_and_notification_floors(): void
    {
        $user = $this->user('1001');
        $wallets = app(WalletService::class);
        $existing = $wallets->add($user, 'ethereum', $this->address(1), 'Keep this private label', 50, 'incoming', '1000');
        DB::table('scan_cursors')->where('chain', 'ethereum')->update(['last_block' => 150]);
        DB::table('wallet_subscriptions')->where('user_wallet_id', $existing->id)->update(['notification_start_block' => 151]);
        $file = $this->file($this->address(1)."\n".$this->address(1)."\n".$this->address(2)."\n");
        $options = ['--chain' => 'ethereum', '--start-block' => '80', '--direction' => 'outgoing', '--min-amount' => '5000'];

        $this->assertSame(0, $this->import($file, $user, $options));
        $this->assertSame(0, $this->import($file, $user, $options));

        $this->assertDatabaseCount('wallets', 2);
        $this->assertDatabaseCount('user_wallets', 2);
        $this->assertDatabaseCount('wallet_subscriptions', 2);
        $this->assertDatabaseHas('user_wallets', ['id' => $existing->id, 'label' => 'Keep this private label', 'start_block' => 50]);
        $this->assertDatabaseHas('wallet_subscriptions', [
            'user_wallet_id' => $existing->id, 'direction' => 'incoming', 'min_amount' => '1000', 'notification_start_block' => 151,
        ]);
        $this->assertSame(150, (int) DB::table('scan_cursors')->where('chain', 'ethereum')->where('role', 'primary')->value('last_block'));
        Http::assertNothingSent();
    }

    public function test_text_import_is_scoped_to_the_selected_user_without_changing_another_owners_settings(): void
    {
        $alice = $this->user('1001');
        $bob = $this->user('1002');
        $owned = app(WalletService::class)->add($bob, 'ethereum', $this->address(1), 'Bob private wallet', 100, 'outgoing', '9000');
        $file = $this->file($this->address(1));

        $this->assertSame(0, $this->import($file, $alice, ['--chain' => 'ethereum', '--start-block' => '100']));

        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('user_wallets', 2);
        $this->assertDatabaseHas('user_wallets', ['id' => $owned->id, 'user_id' => $bob, 'label' => 'Bob private wallet']);
        $this->assertDatabaseHas('wallet_subscriptions', ['user_id' => $bob, 'direction' => 'outgoing', 'min_amount' => '9000']);
        $this->assertDatabaseHas('user_wallets', ['user_id' => $alice, 'wallet_id' => $owned->wallet_id]);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_or_missing_text_options_never_commit_wallets(array $options): void
    {
        $user = $this->user('1001');
        $file = $this->file($this->address(1));

        $this->assertSame(1, $this->import($file, $user, $options));

        $this->assertNoWalletWrites();
        Http::assertNothingSent();
    }

    public static function invalidOptions(): array
    {
        return [
            'missing chain' => [['--start-block' => '100']],
            'unsupported chain' => [['--chain' => 'bitcoin', '--start-block' => '100']],
            'negative start block' => [['--chain' => 'ethereum', '--start-block' => '-1']],
            'overflowing start block' => [['--chain' => 'ethereum', '--start-block' => str_repeat('9', 30)]],
            'invalid direction' => [['--chain' => 'ethereum', '--start-block' => '100', '--direction' => 'sideways']],
            'fractional raw minimum' => [['--chain' => 'ethereum', '--start-block' => '100', '--min-amount' => '1.5']],
            'negative raw minimum' => [['--chain' => 'ethereum', '--start-block' => '100', '--min-amount' => '-1']],
            'unsupported file format' => [['--format' => 'yaml', '--chain' => 'ethereum', '--start-block' => '100']],
        ];
    }

    #[DataProvider('explicitTextExtensions')]
    public function test_explicit_text_format_accepts_files_without_a_txt_extension(string $extension): void
    {
        $user = $this->user('1001');
        $file = $this->file($this->address(1), $extension);

        $this->assertSame(0, $this->import($file, $user, ['--format' => 'txt', '--chain' => 'ethereum', '--start-block' => '100']));

        $this->assertDatabaseHas('wallets', ['chain' => 'ethereum', 'address' => $this->address(1)]);
        Http::assertNothingSent();
    }

    public static function explicitTextExtensions(): array
    {
        return [['list'], ['']];
    }

    #[DataProvider('invalidTextEncodings')]
    public function test_invalid_encoding_or_nul_in_comments_rejects_the_whole_file(string $suffix): void
    {
        $user = $this->user('1001');
        $file = $this->file($this->address(1)."\n# Comment ".$suffix."\n");

        $this->assertSame(1, $this->import($file, $user, ['--chain' => 'ethereum', '--start-block' => '100']));

        $this->assertNoWalletWrites();
        Http::assertNothingSent();
    }

    public static function invalidTextEncodings(): array
    {
        return [["\xFF"], ["\0"]];
    }

    #[DataProvider('structuredFormats')]
    public function test_existing_json_and_csv_imports_keep_their_per_record_settings(string $format): void
    {
        $user = $this->user('1001');
        $file = $this->structuredFile($format);

        $this->assertSame(0, $this->import($file, $user, []));

        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseHas('user_wallets', ['user_id' => $user, 'label' => 'Structured wallet', 'start_block' => 100]);
        $this->assertDatabaseHas('wallet_subscriptions', ['user_id' => $user, 'direction' => 'incoming', 'min_amount' => '1200']);
        Http::assertNothingSent();
    }

    public static function structuredFormats(): array
    {
        return [['json'], ['csv']];
    }

    #[DataProvider('structuredFormatOverrides')]
    public function test_text_flags_cannot_silently_override_structured_import_records(string $format, array $options): void
    {
        $user = $this->user('1001');
        $file = $this->structuredFile($format);

        $this->assertSame(1, $this->import($file, $user, $options));

        $this->assertNoWalletWrites();
        Http::assertNothingSent();
    }

    public static function structuredFormatOverrides(): array
    {
        $cases = [];
        foreach (['json', 'csv'] as $format) {
            foreach (['--chain' => 'tron', '--start-block' => '0', '--direction' => 'outgoing', '--min-amount' => '1'] as $option => $value) {
                $cases[$format.' '.$option] = [$format, [$option => $value]];
            }
        }

        return $cases;
    }

    #[DataProvider('emptyLists')]
    public function test_empty_text_lists_are_rejected_without_writes(string $contents): void
    {
        $user = $this->user('1001');
        $file = $this->file($contents);

        $this->assertSame(1, $this->import($file, $user, ['--chain' => 'ethereum', '--start-block' => '100']));

        $this->assertNoWalletWrites();
        Http::assertNothingSent();
    }

    public static function emptyLists(): array
    {
        return [[''], ["\r\n \t\r\n# No active wallets\r\n"]];
    }

    public function test_more_than_one_hundred_text_records_rejects_the_entire_batch(): void
    {
        $user = $this->user('1001');
        $file = $this->file(implode("\n", array_map($this->address(...), range(1, 101))));

        $this->assertSame(1, $this->import($file, $user, ['--chain' => 'ethereum', '--start-block' => '100']));

        $this->assertNoWalletWrites();
        Http::assertNothingSent();
    }

    public function test_oversized_text_is_rejected_even_when_excess_bytes_are_only_a_comment(): void
    {
        $user = $this->user('1001');
        $file = $this->file($this->address(1)."\n#".str_repeat('x', 1024 * 1024)."\n");

        $this->assertSame(1, $this->import($file, $user, ['--chain' => 'ethereum', '--start-block' => '100']));

        $this->assertNoWalletWrites();
        Http::assertNothingSent();
    }

    public function test_explicit_active_user_is_required_for_text_import(): void
    {
        $user = $this->user('1001');
        $file = $this->file($this->address(1));

        $this->assertSame(1, Artisan::call('wallet:import', ['file' => $file, '--chain' => 'ethereum', '--start-block' => '100']));
        DB::table('access_grants')->where('user_id', $user)->update(['revoked_at' => now()]);
        $this->assertSame(1, $this->import($file, $user, ['--chain' => 'ethereum', '--start-block' => '100']));

        $this->assertNoWalletWrites();
        Http::assertNothingSent();
    }

    public function test_text_default_start_fetches_finality_once_for_the_batch_and_skips_old_activity(): void
    {
        $user = $this->user('1001');
        config(['blockchain.chains.ethereum.providers.primary.rpc_url' => 'https://ethereum.example.test']);
        Http::fake(fn ($request) => Http::response([
            'jsonrpc' => '2.0', 'id' => $request['id'],
            'result' => $request['method'] === 'eth_chainId' ? '0x1' : [
                'number' => '0xc8', 'hash' => '0x'.str_repeat('a', 64), 'timestamp' => '0x6553f100',
            ],
        ]));
        $file = $this->file($this->address(1)."\n".$this->address(2)."\n");

        $this->assertSame(0, $this->import($file, $user, ['--chain' => 'ethereum']));

        $this->assertSame([201], DB::table('user_wallets')->pluck('start_block')->unique()->all());
        $this->assertSame(200, (int) DB::table('scan_cursors')->where('chain', 'ethereum')->value('last_block'));
        $this->assertDatabaseCount('backfill_jobs', 0);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['method'] === 'eth_getBlockByNumber' && $request['params'] === ['finalized', false]);
    }

    private function import(string $file, int $user, array $options): int
    {
        return Artisan::call('wallet:import', ['file' => $file, '--user' => (string) $user] + $options);
    }

    private function user(string $telegramId): int
    {
        $access = app(AccessService::class);

        return $access->activate($access->issue()->token, $telegramId)->userId;
    }

    private function file(string $contents, string $extension = 'txt'): string
    {
        $file = sys_get_temp_dir().'/coin-bot-text-wallets-'.bin2hex(random_bytes(12)).($extension === '' ? '' : '.'.$extension);
        file_put_contents($file, $contents);
        chmod($file, 0600);
        $this->files[] = $file;

        return $file;
    }

    private function structuredFile(string $format): string
    {
        $record = [
            'chain' => 'ethereum', 'address' => $this->address(1), 'label' => 'Structured wallet',
            'start_block' => 100, 'direction' => 'incoming', 'min_amount' => '1200',
        ];
        $contents = $format === 'json'
            ? json_encode([$record], JSON_THROW_ON_ERROR)
            : 'chain,address,label,start_block,direction,min_amount'."\n".implode(',', $record)."\n";

        return $this->file($contents, $format);
    }

    private function address(int $number): string
    {
        return '0x'.str_pad(dechex($number), 40, '0', STR_PAD_LEFT);
    }

    private function assertNoWalletWrites(): void
    {
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('user_wallets', 0);
        $this->assertDatabaseCount('wallet_subscriptions', 0);
        $this->assertDatabaseCount('chat_destinations', 0);
        $this->assertDatabaseCount('scan_cursors', 0);
    }
}
