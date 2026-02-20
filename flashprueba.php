<?php

/**
 * Verifica requests en flashpost.db y detecta problemas de carga en la UI
 */
function checkFlashpostRequestsAdvanced($dbPath)
{
    if (!file_exists($dbPath)) {
        echo "Archivo no encontrado: $dbPath\n";
        return false;
    }

    $db = json_decode(file_get_contents($dbPath), true);
    if (!$db || !isset($db['collections'])) {
        echo "JSON inválido o no contiene collections.\n";
        return false;
    }

    $total = 0;
    $warn = 0;

    foreach ($db['collections'] as $collection) {
        if ($collection['name'] !== 'apiRequests') continue;

        foreach ($collection['data'] as $req) {
            $total++;
            $issues = [];

            // Campos obligatorios
            if (empty($req['id'])) $issues[] = 'id';
            if (empty($req['method'])) $issues[] = 'method';
            if (!isset($req['url'])) $issues[] = 'url';

            // Arrays críticos
            if (!isset($req['params']) || !is_array($req['params']) || empty($req['params'])) $issues[] = 'params (vacío)';
            if (!isset($req['pathParams']) || !is_array($req['pathParams'])) $issues[] = 'pathParams';
            if (!isset($req['auth']) || !is_array($req['auth'])) $issues[] = 'auth';
            if (!isset($req['headers']) || !is_array($req['headers']) || empty($req['headers'])) $issues[] = 'headers (vacío)';
            
            // Body interno
            if (!isset($req['body']) || !is_array($req['body'])) {
                $issues[] = 'body';
            } else {
                if (!isset($req['body']['formdata']) || !is_array($req['body']['formdata'])) $issues[] = 'body.formdata';
                if (!isset($req['body']['urlencoded']) || !is_array($req['body']['urlencoded'])) $issues[] = 'body.urlencoded';
                if (!isset($req['body']['raw']) || !is_array($req['body']['raw'])) $issues[] = 'body.raw';
                if (!isset($req['body']['binary']) || !is_array($req['body']['binary'])) $issues[] = 'body.binary';
                if (!isset($req['body']['graphql']) || !is_array($req['body']['graphql'])) $issues[] = 'body.graphql';
            }

            // Tests y setvar
            if (!isset($req['tests']) || !is_array($req['tests']) || empty($req['tests'])) $issues[] = 'tests (vacío)';
            if (!isset($req['setvar']) || !is_array($req['setvar'])) $issues[] = 'setvar';

            if (!empty($issues)) {
                $warn++;
                echo "⚠️ Request '{$req['name']}' ({$req['id']}) tiene problemas: " . implode(', ', $issues) . "\n";
            }
        }
    }

    echo "\nResumen: Total requests: $total, con problemas: $warn\n";
    return true;
}

// Uso
$userHome = getenv('USERPROFILE');
$dbPath = $userHome . '/Documents/Flashpost/flashpost.db';
checkFlashpostRequestsAdvanced($dbPath);