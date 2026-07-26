# Contributing

Use PHP 8.4 and install dependencies with Composer. Before submitting a change, run:

```bash
composer validate --strict
composer style
composer phpstan
composer test
```

Redis integration tests require a real Redis instance. Do not replace integration behavior with mocks.
