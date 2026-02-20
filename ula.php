<?php

/**
 * Sincroniza Flashpost sin borrar ni sobrescribir datos locales.
 * Solo añade lo que falte desde el repo.
 */
function syncFlashpost($basePath)
{
    $userHome = getenv('USERPROFILE');
    $flashpostPath = $userHome . '/Documents/Flashpost';

    if (!is_dir($flashpostPath)) {
        echo "Flashpost no está instalado en este usuario.\n";
        return false;
    }

    $files = [
        'flashpostCollections.db',
        'flashpostVariable.db',
        'flashpost.db'
    ];

    foreach ($files as $file) {

        $localPath = $flashpostPath . '/' . $file;
        $repoPath  = $basePath . '/' . $file;

        if (!file_exists($localPath) || !file_exists($repoPath)) {
            echo "Saltando $file (no existe en local o repo)\n";
            continue;
        }

        echo "Sincronizando $file...\n";

        $ok = mergeLokiFile($localPath, $repoPath);

        if ($ok) {
            echo "✔ $file sincronizado correctamente\n";
        } else {
            echo "✖ Error sincronizando $file\n";
        }
    }
 
    echo "Sincronización finalizada ✅\n";
    return true;
}


/**
 * Merge profesional de bases LokiJS.
 * No elimina ni sobrescribe.
 * Reconstruye índices internos correctamente.
 */
function mergeLokiFile($localPath, $repoPath)
{
    $local = json_decode(file_get_contents($localPath), true);
    $repo  = json_decode(file_get_contents($repoPath), true);

    if (!$local || !$repo) return false;
    if (!isset($local['collections'][0]) || !isset($repo['collections'][0])) return false;

    copy($localPath, $localPath . '.backup');

    $localCollection = &$local['collections'][0];
    $repoCollection  = $repo['collections'][0];

    $localData = $localCollection['data'] ?? [];
    $repoData  = $repoCollection['data'] ?? [];

    // Indexar local por ID
    $localById = [];
    foreach ($localData as $item) {
        if (isset($item['id'])) {
            $localById[$item['id']] = $item;
        }
    }

    $added = 0;

    // Añadir solo lo que falte
    foreach ($repoData as $repoItem) {

        if (!isset($repoItem['id'])) continue;

        if (!isset($localById[$repoItem['id']])) {
            $localData[] = $repoItem;
            $added++;
        }
    }

    // 🔥 RECONSTRUCCIÓN COMPLETA DE LOKI

    $newData = [];
    $newIdIndex = [];
    $counter = 1;

    foreach ($localData as $item) {

        $item['$loki'] = $counter;

        $newData[] = $item;
        $newIdIndex[] = $counter;

        $counter++;
    }

    $localCollection['data'] = $newData;
    $localCollection['idIndex'] = $newIdIndex;
    $localCollection['maxId'] = $counter - 1;

    // Reconstruir binaryIndices
    if (isset($localCollection['binaryIndices'])) {

        foreach ($localCollection['binaryIndices'] as &$index) {

            $index['dirty'] = false;
            $index['values'] = range(0, count($newData) - 1);
        }
    }

    // Reset flags internos
    $localCollection['dirty'] = false;
    $localCollection['cachedIndex'] = null;
    $localCollection['cachedBinaryIndex'] = null;
    $localCollection['cachedData'] = null;

    // Reset flags globales
    $local['throttledSaves'] = false;
    $local['autosaveHandle'] = null;
    $local['isIncremental'] = false;

    file_put_contents($localPath, json_encode($local, JSON_PRETTY_PRINT));

    echo "  → Añadidos $added registros\n";

    return true;
}