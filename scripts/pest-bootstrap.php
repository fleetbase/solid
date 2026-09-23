<?php

declare(strict_types=1);

/**
 * Prepended to every Pest run by scripts/pest-runner.php.
 *
 * Deliberately almost empty, and in particular it must NOT require the Composer
 * autoloader. Storefront's equivalent does, because its shims reference Illuminate
 * classes at prepend time — but loading the autoloader before Pest boots leaves
 * Pest's reporter with nothing to print: the run still executes and still exits 0,
 * and the report silently disappears. The tests here build the container they need
 * themselves (server/tests/Support/OidcTestCase.php), so there is nothing to shim,
 * and Pest loads the autoloader itself through the symlink the runner creates.
 */

// pestphp/pest#920: Pest's binary include_once's ../../../vendor/autoload.php,
// which does not exist when a package installs to a vendor-dir other than
// `vendor`. scripts/pest-runner.php works around that with a temporary symlink;
// this keeps the warning off the report if the symlink cannot be created.
set_error_handler(static function (int $severity, string $message): bool {
    return str_contains($message, '/pestphp/pest/vendor/autoload.php');
}, E_WARNING);
