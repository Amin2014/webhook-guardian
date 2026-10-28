# Validation results

Executed on 2026-10-27 against the delivered source.

Environment: PHP 8.3.6, SQLite 3.45.1, Composer 2.10.3,
PHPUnit 11.5.57, PHPStan 2.3.2. Development versions are recorded in composer.lock.
PHP and extensions were provisioned locally because the workspace initially had no PHP.

| Check | Actual result |
| --- | --- |
| `composer install --no-interaction --prefer-dist` | Passed with all required platform extensions enabled |
| `composer validate --strict` | Passed |
| `composer check-platform-reqs` | All requirements passed |
| `composer test` (via `composer check`) | 53 tests, 108 assertions; no failures or skips |
| `composer analyse` (via `composer check`) | Maximum level, no errors |
| Syntax checks on all 20 PHP files | Passed |
| `php examples/demo.php` | accepted, duplicate, one outbox row |
| `composer audit` | No security vulnerability advisories found |

Concurrency tests use eight independent PHP processes against one file-backed
SQLite WAL database. Both identical-payload and conflicting-payload races passed,
with exactly one committed reservation and one committed business effect.

PHPStan's configuration treats PHPDoc types as uncertain so defensive validation
of public array inputs remains checked without assuming every caller obeys PHPDoc.
There are no ignored error baselines. Source and examples are statically analysed.

PHP 8.4 and 8.5 are configured in GitHub Actions but were not executed locally.
No remote CI run, public GitHub repository, or Packagist publication is claimed.
Composer's root-version fallback notice is expected for an untagged source checkout.
These automated checks are not an independent security audit.
