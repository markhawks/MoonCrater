<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const APP_PUBLIC_DIR = APP_ROOT . '/public';
const APP_MIGRATIONS_DIR = APP_ROOT . '/migrations';
const APP_VAR_DIR = APP_ROOT . '/var';

function app_asset(string $path): string
{
    return 'assets/' . ltrim($path, '/');
}
