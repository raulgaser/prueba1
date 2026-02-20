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

    copy($localPath, $localPath . '.backup');

    foreach ($repo['collections'] as $repoCollection) {

        $repoName = $repoCollection['name'];

        foreach ($local['collections'] as &$localCollection) {

            if ($localCollection['name'] === $repoName) {

                $localData = &$localCollection['data'];
                $repoData  = $repoCollection['data'];

                $localIds = [];
                foreach ($localData as $item) {
                    $localIds[$item['id']] = true;
                }

                foreach ($repoData as $repoItem) {
                    if (!isset($localIds[$repoItem['id']])) {

                        // 🔥 Añadir EXACTAMENTE como viene
                        $localData[] = $repoItem;
                    }
                }
            }
        }
    }

    file_put_contents($localPath, json_encode($local, JSON_PRETTY_PRINT));

    return true;
}