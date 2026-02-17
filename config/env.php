<?php
/**
 * root_folder/config/env.php
 * Lightweight .env loader (no external dependency)
 */

if (!function_exists('load_project_env')) {
    function load_project_env($envPath = null)
    {
        static $isLoaded = false;
        if ($isLoaded) {
            return;
        }

        $rootPath = dirname(__DIR__);
        $filePath = $envPath ?: $rootPath . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($filePath) || !is_readable($filePath)) {
            $isLoaded = true;
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $isLoaded = true;
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (strpos($line, '=') === false) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if ($name === '') {
                continue;
            }

            if (stripos($name, 'export ') === 0) {
                $name = trim(substr($name, 7));
            }

            if (
                $value !== '' &&
                (
                    ($value[0] === '"' && substr($value, -1) === '"') ||
                    ($value[0] === "'" && substr($value, -1) === "'")
                )
            ) {
                $value = substr($value, 1, -1);
            } else {
                $hashPos = strpos($value, '#');
                if ($hashPos !== false) {
                    $value = rtrim(substr($value, 0, $hashPos));
                }
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        $isLoaded = true;
    }
}

load_project_env();
