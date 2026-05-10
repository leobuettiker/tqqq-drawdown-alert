# TQQQ Drawdown Alert

Minimal PHP/MariaDB utility for monitoring TQQQ historical NAV data, tracking all-time highs, detecting configured drawdown levels, and producing cron-friendly alert output until each buy has been confirmed.

This project was vibe-coded as a small self-hosted tool. Treat it like any other operational script: review it, test it on your own hosting, and keep backups of your database and configuration.

A public example dashboard is available at: https://tqqq.buettiker.org

## Features

- PHP-only implementation
- No framework
- No Composer dependency
- No external PHP libraries
- PDO + MariaDB
- Historical NAV import from the ProFunds CSV source
- Full `price_history` rebuild on each import, which keeps split/rebased histories simple
- ATH tracking based on imported NAV values
- Drawdown strategy levels from a local CSV file
- Cron-friendly alert output: no output means no alert; output means error or active unconfirmed alert
- Optional PHP `mail()` configuration for controlled email sender, recipient and subject metadata
- Browser-callable `mail-test.php`, disabled by default, for validating local mail delivery
- Protected buy confirmation screen
- Public dashboard without strategy, buy amount, buy price, or alert details
- Mobile-friendly UI
- Chart.js dashboard chart with NAV, ATH, drawdown tooltip, and fullscreen mode

## Project structure

```text
/
├── index.php
├── mail-test.php
├── assets/
│   └── style.css
├── app/
│   ├── .htaccess
│   ├── db.php
│   ├── functions.php
│   ├── import_tqqq_nav.php
│   └── check_drawdown_alerts.php
├── examples/
│   ├── config.example.php
│   ├── drawdown_strategy.example.csv
│   └── local.htaccess.example
├── protected/confirm/
│   ├── .htaccess
│   ├── index.php
│   └── style.css
├── sql/
│   ├── .htaccess
│   └── schema.sql
├── .htaccess
└── README.md
```

## Local configuration

The application expects a server-local `local/` directory:

```text
local/
├── .htaccess
├── config.php
└── drawdown_strategy.csv
```

The `local/` directory is intentionally not included in the release ZIP. This lets you replace the application code during updates without overwriting your production configuration or strategy file.

Create it once during installation:

```bash
mkdir local
cp examples/config.example.php local/config.php
cp examples/drawdown_strategy.example.csv local/drawdown_strategy.csv
cp examples/local.htaccess.example local/.htaccess
```

Then edit `local/config.php`.

### Mail configuration

The example configuration contains a `mail` block:

```php
'mail' => [
    'enabled' => true,
    'test_enabled' => false,
    'to_email' => 'CHANGE_ME_TO@example.com',
    'from_email' => 'CHANGE_ME_FROM@example.com',
    'from_name' => 'TQQQ Drawdown Alert',
    'reply_to' => 'CHANGE_ME_FROM@example.com',
    'subject_prefix' => '[TQQQ]',
],
```

`mail-test.php` uses this configuration to send one test email with PHP `mail()`. The test script is disabled by default. Set `test_enabled` to `true` temporarily when you want to test outgoing mail, then set it back to `false` after the test.

This uses the hosting account's normal PHP `mail()` setup. It does not require SMTP credentials in this project.

## Installation

### 1. Upload the files

Upload the ZIP contents directly into a directory on a simple PHP web hosting account.

The public dashboard is served by:

```text
index.php
```

### 2. Create the local configuration

Create the `local/` directory as described above.

Edit:

```text
local/config.php
```

Set at least:

```php
'database' => 'CHANGE_ME_DATABASE',
'user' => 'CHANGE_ME_USER',
'password' => 'CHANGE_ME_PASSWORD',
'confirm_password' => 'CHANGE_ME_CONFIRM_PASSWORD',
```

Also configure the `mail` block if you want to test outgoing mail or use direct mail delivery later.

Use a long random value for `confirm_password`.

### 3. Create the database schema

Create a MariaDB database and import:

```text
sql/schema.sql
```

### 4. Configure the drawdown strategy

Edit:

```text
local/drawdown_strategy.csv
```

Example:

```csv
asset_ticker,level_name,drawdown_percent,amount_to_buy
TQQQ,Level 1,-20,500
TQQQ,Level 2,-30,750
TQQQ,Level 3,-40,1250
TQQQ,Level 4,-50,1500
```

`drawdown_percent` must be negative.

### 5. Test outgoing mail

Temporarily enable the mail test in `local/config.php`:

```php
'test_enabled' => true,
```

Then open the mail test script in the browser:

```text
mail-test.php
```

The page sends one test email using PHP `mail()` and the values from `local/config.php`.

Expected result: the browser shows whether `mail()` returned success. Receiving the test email confirms that the hosting account accepts the configured sender/recipient combination.

After testing, set the flag back to:

```php
'test_enabled' => false,
```

### 6. Run the importer manually

From SSH:

```bash
php app/import_tqqq_nav.php -- --verbose
```

Expected result: the command prints diagnostics and inserts rows into `price_history`.

Then run the normal silent mode:

```bash
php app/import_tqqq_nav.php
```

Expected result: no output on success.

### 7. Open the dashboard

Open the hosting URL where `index.php` is deployed.

The dashboard intentionally does not show strategy levels, configured buy amounts, confirmed buy quantities, confirmed buy prices, or alert details.

### 8. Open the confirmation screen

The confirmation UI is available at:

```text
protected/confirm/index.php
```

It is protected by the configured `confirm_password`. The included `.htaccess` also contains commented instructions for optional HTTP Basic Auth.

### 9. Configure cron jobs

Create two scheduled commands on your hosting account.

Import job, for example after the US market close data is expected to be available:

```bash
php /ABSOLUTE/PATH/app/import_tqqq_nav.php
```

Alert job, for example later in the morning:

```bash
php /ABSOLUTE/PATH/app/check_drawdown_alerts.php
```

Use a hosting notification setting that sends the command output by email.

The scripts are designed for this behavior:

- successful import: no output
- no active alert: no output
- active unconfirmed alert: formatted text output
- error: formatted error output

## Runtime behavior

### NAV import

The import job:

1. Downloads the ProFunds historical NAV CSV.
2. Validates the expected header.
3. Parses all valid TQQQ NAV rows.
4. Deletes all existing rows in `price_history`.
5. Inserts the freshly parsed history.
6. Computes the ATH.
7. Updates `asset_ath`.
8. Writes `job_log`.
9. Produces no output on normal success.

### ATH and reverse split handling

A new drawdown cycle starts only when the newly computed ATH is higher **and** has a later date than the previously stored ATH.

This avoids treating a reverse split, NAV rebasing, or historical data adjustment as a new economic ATH cycle. In those cases, `asset_ath` can be updated while existing open or confirmed alert states remain in the same cycle.

### Alerting

The alert job:

1. Reads `local/drawdown_strategy.csv`.
2. Loads the latest NAV and stored ATH.
3. Computes the current drawdown.
4. Creates an alert state when a strategy level is reached.
5. Outputs all active unconfirmed alerts.
6. Produces no output if no unconfirmed alert is active.

Each strategy level must be confirmed individually.

### Mail test

`mail-test.php` is a small operational helper. It:

1. Loads `local/config.php`.
2. Requires `mail.test_enabled` to be `true`.
3. Reads the `mail` configuration block.
4. Sends one UTF-8 plain text test email using PHP `mail()`.
5. Shows the result in the browser.

It is intentionally simple and does not store any mail state in the database.

### Buy confirmation

For every confirmation, the tool stores:

- confirmation timestamp
- quantity bought
- buy price
- optional note

## Security notes

- `app/`, `sql/`, and `local/` should not be publicly browsable.
- The root `.htaccess` blocks direct access to internal directories when supported by the web server.
- Copy `examples/local.htaccess.example` to `local/.htaccess`.
- The confirmation area requires the configured `confirm_password`.
- Optional HTTP Basic Auth can be enabled in `protected/confirm/.htaccess`.
- `mail-test.php` is disabled by default and only sends mail when `mail.test_enabled` is explicitly set to `true`.
- Set `mail.test_enabled` back to `false` after testing.
- Never commit or upload production secrets to a public repository.

## Updating

Keep your existing `local/` directory.

Replace the application files around it, for example:

```text
app/
assets/
protected/
examples/
sql/
index.php
mail-test.php
.htaccess
README.md
```

Do not overwrite:

```text
local/config.php
local/drawdown_strategy.csv
```

## Development notes

Run a syntax check before deploying changes:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

The project intentionally stays small and boring: plain PHP files, MariaDB tables, CSV configuration, and cron output.
