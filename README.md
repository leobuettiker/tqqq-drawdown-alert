# TQQQ Drawdown Alert

Minimal PHP/MariaDB utility for monitoring TQQQ historical NAV data, tracking all-time highs, detecting configured drawdown levels, and producing cron-friendly alert output until each buy has been confirmed.

This project was vibe-coded as a small self-hosted tool.

A public example dashboard is available at: https://tqqq.buettiker.org

## Features

- PHP-only implementation
- No framework
- No Composer dependency
- No external PHP libraries
- PDO + MariaDB
- Historical NAV import from the ProFunds CSV source
- ATH tracking
- Drawdown alerting
- Mail test helper
- AI-generated periodic summaries using OpenAI Responses API with web search
- Public dashboard
- Protected confirmation screen
- Mobile-friendly UI

## Project structure

```text
app/
├── import_tqqq_nav.php
├── check_drawdown_alerts.php
└── send_periodic_summary.php
```

## AI summary configuration

Add this block to `local/config.php`:

```php
'summary' => [
    'enabled' => true,
    'minimum_interval_days' => 7,
    'openai_api_key' => 'CHANGE_ME_OPENAI_API_KEY',
    'openai_model' => 'gpt-5',
    'reasoning_effort' => 'low',
    'openai_timeout_seconds' => 90,
],
```

Behavior:

- always sends a summary when executed
- uses at least the configured minimum interval
- automatically expands the lookback window if the previous run is older
- uses local NAV and drawdown data
- additionally performs web research
- always generates English plaintext output
- keeps the tone constructive and optimistic

## Cron job

```bash
php /ABSOLUTE/PATH/app/send_periodic_summary.php
```

## Runtime behavior

The summary script:

1. Determines the effective lookback period.
2. Reads NAV, ATH, drawdown, and alert statistics.
3. Calls OpenAI Responses API.
4. Enables web search.
5. Generates a short positive market summary.
6. Sends the result via PHP `mail()`.
7. Writes a `job_log` entry.
8. Produces no output on success.

## Security notes

- Store the OpenAI API key only in `local/config.php`.
- Never commit production secrets.
- `mail-test.php` is disabled by default.
