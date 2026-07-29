## What

<!-- What changes, and why. Reference the issue if one exists. -->

## Checklist

- [ ] `vendor/bin/phpunit` green locally
- [ ] `vendor/bin/phpstan analyse` clean
- [ ] `php-cs-fixer` applied
- [ ] No em dashes in any copy
- [ ] `resources/specs/` untouched by hand (regenerated via `bin/sync-specs.php` only)
- [ ] If `/start` code samples changed, `tests/Tutorial/TodoAppTest.php` changed with them
- [ ] If a framework defect was found: minimal repro filed upstream, no app-level bypass added
- [ ] If `waaseyaa/*` versions changed: this PR is a dedicated framework-upgrade PR with lock diff, re-synced corpus, and deploy notes
