# Bulk wallet import from a text file

Create a UTF-8 `.txt` file with **one complete public wallet address per line**, without numbering, quotes, or a header. Use separate files for Ethereum and TRON. Empty lines and full-line `#` comments are ignored; Windows line endings and a UTF-8 BOM are supported.

Example Ethereum file (replace these sample addresses with your own):

```text
# Ethereum wallets
0x1111111111111111111111111111111111111111
0x2222222222222222222222222222222222222222
```

TRON files use the same layout with your complete `T...` addresses. The chain is explicit because some hex addresses are valid input for both chains; the importer never guesses it.

## Native CLI

The intended Telegram user must first activate an individual key. Find their **internal User ID**, which is different from their Telegram ID:

```bash
sudo coin-bot access:users
```

The helper runs as `coin-bot`, so copy the list into its private storage. From the directory containing your `wallets-tron.txt`, replace `USER_ID` below:

```bash
sudo install -o coin-bot -g coin-bot -m 0600 wallets-tron.txt /home/www/coinbot/storage/app/wallets-tron.txt
sudo coin-bot wallet:import /home/www/coinbot/storage/app/wallets-tron.txt --chain=tron --user=USER_ID
sudo coin-bot wallet:list --user=USER_ID
```

For Ethereum, use your Ethereum file and `--chain=ethereum`. Both the user and chain are required for TXT. Files in an operator's private home may be unreadable to the application user; the copy step above avoids that problem.

## Limits and behavior

- Up to **100 address lines** per command, including repeated addresses; comments/empty lines do not count. Maximum actual file size is **1 MiB**.
- The whole batch is validated and committed atomically. A bad address, invalid setting, missing/revoked access, oversized file, or excess row count leaves the database unchanged.
- Reimporting a list or repeating an address creates no duplicate user wallets. Existing labels, enabled/paused state, filters, and notification boundaries remain unchanged. Other users' settings stay private.
- New wallets start at the **next finalized block** by default; initial historical notifications are not sent.
- `--start-block=NUMBER`, `--direction=both|incoming|outgoing`, and `--min-amount=INTEGER` set defaults for new TXT records. Minimum amount uses raw USDT units: `1000000` means `1 USDT`. An explicitly earlier block follows `wallet:add` behavior and can produce historical notifications, especially for the first wallet on a chain; omit it for normal monitoring from the next finalized block.
- For a text file without `.txt`, add `--format=txt`. `.TXT` is recognized automatically. UTF-16 files and control characters are rejected.
- Existing CSV/JSON record imports remain available. Use `--format=csv` or `--format=json` for an unusual extension. TXT-only options cannot override CSV/JSON records; put each record's settings in those formats instead.

Importing changes monitored public addresses and user subscriptions only. It does not alter PHP packages, FPM/Nginx configuration, or the bot's private access rules. Keep wallet lists out of Git and the public web directory; no private keys or seed phrases are needed.
