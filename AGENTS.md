# Project instructions

- Write code, documentation, commit messages, and pull request descriptions in English. Communicate with the user in Russian.
- Complete the task's relevant checks, then commit, push, and merge task changes automatically within the authorized scope. Routine confirmation is not required. Respect branch protection and preserve existing work; do not force-push.
- The selected stack is PHP 8.5, Laravel, and SQLite. Follow `docs/architecture.md` for the notification-only USDT monitoring design. Explain any necessary architecture change before implementing it.
- Never store wallet private keys or seed phrases. This application only reads public blockchain data and sends authorized Telegram notifications.
- Keep credentials out of source control, logs, and documentation.
