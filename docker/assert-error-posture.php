<?php

declare(strict_types=1);

/**
 * Asserts the error-reporting posture of the image this runs inside.
 *
 * Diagnostics must never be displayed to visitors and must always be logged.
 * PHP ships the opposite defaults (display_errors=On, log_errors=Off) and the
 * official base images activate neither php.ini-development nor
 * php.ini-production, so the posture is asserted rather than assumed.
 *
 * Run against every image that is built: docker run --rm IMAGE php
 * /app/docker/assert-error-posture.php
 */

$bad = [];

// ini_get returns "" for Off and "1" for On.
foreach (['display_errors' => '', 'display_startup_errors' => '', 'log_errors' => '1'] as $key => $want) {
    $got = ini_get($key);
    if ($got !== $want) {
        $bad[] = sprintf('%s=%s (wanted %s)', $key, var_export($got, true), var_export($want, true));
    }
}

// error_log must stay unset. Setting it makes PHP prefix every error_log()
// call with a timestamp, which would corrupt the single-line JSON that
// Support\OperationalLog writes through that same function. Left unset,
// diagnostics still reach the container log through the SAPI error stream.
if (ini_get('error_log') !== '') {
    $bad[] = sprintf(
        'error_log=%s (must stay unset so JSON log lines are not prefixed)',
        var_export(ini_get('error_log'), true),
    );
}

if ($bad !== []) {
    fwrite(STDERR, 'error-reporting posture wrong: ' . implode(', ', $bad) . PHP_EOL);
    exit(1);
}

echo 'error-reporting posture ok', PHP_EOL;
