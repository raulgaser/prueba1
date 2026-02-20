<?php
/**
 * Sincronización profesional de Flashpost (version “multiusuario”)
 * Compatible con requests de otro usuario, mantiene todas las requests locales
 */

function syncFlashpost($repoPath)
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
        $localPath = $flashpostPath . DIRECTORY_SEPARATOR . $file;
        $repoFile  = $repoPath . DIRECTORY_SEPARATOR . $file;

        if (!file_exists($localPath)) {
            echo "⚠ Archivo local no encontrado: $file\n";
            continue;
        }

        if (!file_exists($repoFile)) {
            echo "⚠ Archivo repo no encontrado: $file\n";
            continue;
        }

        echo "🔁 Sincronizando $file...\n";

        if ($file === 'flashpostCollections.db') {
            mergeCollections($localPath, $repoFile);
        } else {
            mergeLokiDb($localPath, $repoFile);
        }
    }

    echo "✅ Sincronización completa.\n";
    return true;
}


/**
 * Merge de colecciones
 * - Carpeta: copia tal cual
 * - Request: solo id y treeNodeType
 */
function mergeCollections($localPath, $repoPath)
{
    $local = json_decode(file_get_contents($localPath), true);
    $repo  = json_decode(file_get_contents($repoPath), true);

    copy($localPath, $localPath . '.backup');

    foreach ($repo['collections'] as $repoCollection) {

        $found = false;
        foreach ($local['collections'] as &$localCollection) {
            if ($localCollection['name'] === $repoCollection['name']) {
                $found = true;

                $existingIds = [];
                foreach ($localCollection['data'] as $item) {
                    $existingIds[$item['id']] = true;
                }

                foreach ($repoCollection['data'] as $repoItem) {
                    if (!isset($existingIds[$repoItem['id']])) {
                        if (!empty($repoItem['droppable'])) {
                            // Carpeta o colección
                            $localCollection['data'][] = $repoItem;
                        } else {
                            // Request: solo id y treeNodeType
                            $localCollection['data'][] = [
                                "id" => $repoItem['id'],
                                "parent" => $repoItem['parent'] ?? "",
                                "text" => $repoItem['text'] ?? "",
                                "createdTime" => $repoItem['createdTime'] ?? date("d-M-Y H:i:s"),
                                "droppable" => false,
                                "data" => [
                                    "treeNodeType" => "request",
                                    "id" => $repoItem['id']
                                ]
                            ];
                        }
                    }
                }
                break;
            }
        }

        if (!$found) {
            // La colección completa no existía localmente
            $local['collections'][] = $repoCollection;
        }
    }

    file_put_contents($localPath, json_encode($local, JSON_PRETTY_PRINT));
}


/**
 * Merge de bases LokiJS (flashpost.db / flashpostVariable.db)
 * - Genera $loki único localmente
 * - Actualiza idIndex
 */
function mergeLokiDb($localPath, $repoPath)
{
    $local = json_decode(file_get_contents($localPath), true);
    $repo  = json_decode(file_get_contents($repoPath), true);

    copy($localPath, $localPath . '.backup');

    foreach ($repo['collections'] as $repoCollection) {

        foreach ($local['collections'] as &$localCollection) {

            if ($localCollection['name'] === $repoCollection['name']) {

                $existingIds = [];
                foreach ($localCollection['data'] as $item) {
                    if (isset($item['id'])) {
                        $existingIds[$item['id']] = true;
                    }
                }

                $maxLoki = !empty($localCollection['data'])
                    ? max(array_column($localCollection['data'], '$loki'))
                    : 0;

                foreach ($repoCollection['data'] as $repoItem) {

                    if (!isset($existingIds[$repoItem['id']])) {
                        $maxLoki++;
                        $repoItem['$loki'] = $maxLoki;
                        $localCollection['data'][] = $repoItem;
                    }
                }

                // Reconstruir idIndex con todos los $loki
                $localCollection['idIndex'] = array_map(function ($item) {
                    return $item['$loki'];
                }, $localCollection['data']);

                // Marcar binaryIndices como dirty
                if (isset($localCollection['binaryIndices'])) {
                    foreach ($localCollection['binaryIndices'] as &$index) {
                        $index['dirty'] = true;
                    }
                }

                break;
            }
        }
    }

    file_put_contents($localPath, json_encode($local, JSON_PRETTY_PRINT));
}

// Ejemplo de uso
// syncFlashpost('C:/ruta/al/repo');