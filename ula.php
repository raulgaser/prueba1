<?php
/**
 * Sincronización profesional Flashpost compatible PHP 7.3
 * 
 * Importa colecciones, requests y variables desde otro repo sin eliminar tus datos locales.
 */

function syncFlashpost($repoPath)
{
    $userHome = getenv('USERPROFILE');
    $flashpostPath = $userHome . '/Documents/Flashpost';

    if (!is_dir($flashpostPath)) {
        echo "Flashpost no instalado.\n";
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

        if (!file_exists($localPath) || !file_exists($repoFile)) {
            echo "⚠ Saltando $file (archivo faltante)\n";
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
 * Merge collections y requests
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

                // IDs existentes
                $existingIds = [];
                foreach ($localCollection['data'] as $item) {
                    $existingIds[$item['id']] = $item['$loki'] ?? 0;
                }

                // maxLoki local
                $maxLoki = 0;
                if (!empty($localCollection['data'])) {
                    foreach ($localCollection['data'] as $item) {
                        if (isset($item['$loki']) && $item['$loki'] > $maxLoki) {
                            $maxLoki = $item['$loki'];
                        }
                    }
                }

                // recorrer nodos del repo
                foreach ($repoCollection['data'] as $repoItem) {
                    if (!isset($existingIds[$repoItem['id']])) {
                        $maxLoki++;

                        // 🚨 Nodo reconstruido con toda la info de `data` del repo
                        $nodeData = $repoItem['data'] ?? [];
                        $nodeData['treeNodeType'] = $repoItem['droppable'] ? 'collection' : 'request';

                        $localCollection['data'][] = [
                            'id' => $repoItem['id'],
                            'parent' => $repoItem['parent'] ?? '',
                            'text' => $repoItem['text'] ?? '',
                            'createdTime' => $repoItem['createdTime'] ?? date("d-M-Y H:i:s"),
                            'droppable' => $repoItem['droppable'] ?? false,
                            'data' => $nodeData,
                            '$loki' => $maxLoki
                        ];

                        $type = ($repoItem['droppable'] ?? false) ? 'Carpeta' : 'Request';
                        echo "📌 $type agregada: {$repoItem['text']} (nuevo \$loki={$maxLoki})\n";
                    }
                }

                // reconstruir idIndex
                $localCollection['idIndex'] = [];
                foreach ($localCollection['data'] as $i) {
                    $localCollection['idIndex'][] = $i['$loki'];
                }

                // marcar binaryIndices como dirty
                if (isset($localCollection['binaryIndices'])) {
                    foreach ($localCollection['binaryIndices'] as &$index) {
                        $index['dirty'] = true;
                    }
                }

                break;
            }
        }

        if (!$found) {
            // colección completa nueva
            $local['collections'][] = $repoCollection;
            echo "🆕 Colección completa agregada: {$repoCollection['name']}\n";
        }
    }

    file_put_contents($localPath, json_encode($local, JSON_PRETTY_PRINT));
}

/**
 * Merge LokiDB (flashpost.db o flashpostVariable.db)
 */
function mergeLokiDb($localPath, $repoPath)
{
    $local = json_decode(file_get_contents($localPath), true);
    $repo  = json_decode(file_get_contents($repoPath), true);

    copy($localPath, $localPath . '.backup');

    foreach ($repo['collections'] as $repoCollection) {
        foreach ($local['collections'] as &$localCollection) {
            if ($localCollection['name'] === $repoCollection['name']) {

                // IDs existentes
                $existingIds = [];
                foreach ($localCollection['data'] as $item) {
                    $existingIds[$item['id']] = true;
                }

                // maxLoki local
                $maxLoki = 0;
                if (!empty($localCollection['data'])) {
                    foreach ($localCollection['data'] as $item) {
                        if (isset($item['$loki']) && $item['$loki'] > $maxLoki) {
                            $maxLoki = $item['$loki'];
                        }
                    }
                }

                foreach ($repoCollection['data'] as $repoItem) {
                    if (!isset($existingIds[$repoItem['id']])) {
                        $maxLoki++;
                        $repoItem['$loki'] = $maxLoki;
                        $localCollection['data'][] = $repoItem;
                        echo "📥 Request/Variable importada: {$repoItem['id']} (nuevo \$loki={$maxLoki})\n";
                    }
                }

                // reconstruir idIndex
                $localCollection['idIndex'] = [];
                foreach ($localCollection['data'] as $i) {
                    $localCollection['idIndex'][] = $i['$loki'];
                }

                // marcar binaryIndices como dirty
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

// === USO ===
// syncFlashpost('C:/ruta/al/repo');