# PHP Syntax Lint

The `lint` suite runs `php -l` on every PHP file in the runtime SUT copy (skipping `.git`, `.runtime`, `node_modules`, `reports`, `vendor`). It answers one cheap question: does the SUT parse under the harness PHP version?

It runs after normal setup, so a setup failure still skips it.

```sh
./test:lint
./test:case baseline-default --suites lint
```

`./test:quick` runs it before `unit` and `integration`.

Output is `logs/lint.log`. A failure is classified `php_lint_failure`, and `first_failed_test` names the first file that failed to parse.
