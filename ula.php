<?php
/**
 * Sincroniza Flashpost correctamente respetando $loki y completando requests
 * Compatible PHP 7.3+
 */

function syncFlashpost($repoPath)
{
    $userHome = getenv('USERPROFILE');
    $flashpostPath = $userHome . '/Documents/Flashpost';

    if (!is_dir($flashpostPath)) {
        echo "Flashpost no está instalado en este usuario.\n";
        return false;
    }

    $filesToSync = [
        'flashpostCollections.db',
        'flashpost.db'
    ];

    foreach ($filesToSync as $fileName) {
        $localPath = $flashpostPath . '/' . $fileName;
        $repoFile  = rtrim($repoPath, '/') . '/' . $fileName;

        if (!file_exists($localPath)) {
            echo "Archivo local no encontrado: $fileName\n";
            continue;
        }

        if (!file_exists($repoFile)) {
            echo "Archivo repo no encontrado: $fileName\n";
            continue;
        }

        echo "🔁 Sincronizando $fileName...\n";

        if ($fileName === 'flashpost.db') {
            mergeRequestsWithLoki($localPath, $repoFile);
        } else {
            syncCollectionNodes($localPath, $repoFile);
        }
    }

    echo "✅ Sincronización completa.\n";
    return true;
}

/**
 * Reconstruye binaryIndices.id.values a partir del array de datos.
 * LokiJS espera un array de posiciones (índice base 0) ordenadas
 * según el valor del campo 'id' de cada registro (orden ascendente).
 */
function rebuildBinaryIndex(array $data): array
{
    // Construimos pares [posicion, valor_id]
    $pairs = [];
    foreach ($data as $pos => $item) {
        $pairs[] = ['pos' => $pos, 'id' => $item['id'] ?? ''];
    }

    // Ordenamos por el campo id de forma ascendente (igual que LokiJS)
    usort($pairs, function ($a, $b) {
        return strcmp($a['id'], $b['id']);
    });

    // Devolvemos solo las posiciones en ese orden
    return array_map(function ($p) { return $p['pos']; }, $pairs);
}

/**
 * Merge requests de flashpost.db respetando $loki y actualizando nodos de collections
 */
function mergeRequestsWithLoki($localPath, $repoPath)
{
    $local = json_decode(file_get_contents($localPath), true);
    $repo  = json_decode(file_get_contents($repoPath), true);

    if (!$local || !$repo) return false;

    copy($localPath, $localPath . '.backup');

    $localRequests = &$local['collections'][0]['data'];
    $repoRequests  = $repo['collections'][0]['data'];

    // Crear mapa de id => $loki local
    $localIdToLoki = [];
    foreach ($localRequests as $item) {
        if (isset($item['id']) && isset($item['$loki'])) {
            $localIdToLoki[$item['id']] = $item['$loki'];
        }
    }

    $usedLoki = array_values($localIdToLoki);
    $maxLoki  = !empty($usedLoki) ? max($usedLoki) : 0;

    foreach ($repoRequests as $repoItem) {
        if (!isset($repoItem['id'])) continue;

        if (isset($localIdToLoki[$repoItem['id']])) {
            // Existe: igualar $loki y actualizar request completa
            $repoItem['$loki'] = $localIdToLoki[$repoItem['id']];
            foreach ($localRequests as &$localItem) {
                if ($localItem['id'] === $repoItem['id']) {
                    $localItem = array_merge($localItem, $repoItem);
                    break;
                }
            }
            unset($localItem);
        } else {
            // Nueva request
            $maxLoki++;
            $repoItem['$loki'] = $maxLoki;
            $localRequests[]   = $repoItem;
            echo "📥 Request importada: {$repoItem['name']} (\$loki={$repoItem['$loki']})\n";
        }
    }

    // Reconstruir idIndex
    $local['collections'][0]['idIndex'] = array_map(function ($i) { return $i['$loki']; }, $localRequests);
    $local['collections'][0]['maxId']   = max(array_column($localRequests, '$loki'));

    // Reconstruir binaryIndices.id.values
    $local['collections'][0]['binaryIndices']['id']['values'] = rebuildBinaryIndex($localRequests);
    $local['collections'][0]['binaryIndices']['id']['dirty']  = false;

    file_put_contents($localPath, json_encode($local, JSON_PRETTY_PRINT));
    return true;
}

/**
 * Sincroniza nodos de flashpostCollections.db respetando $loki y completando datos
 */
function syncCollectionNodes($localPath, $repoPath)
{
    $local = json_decode(file_get_contents($localPath), true);
    $repo  = json_decode(file_get_contents($repoPath), true);

    if (!$local || !$repo) return false;

    copy($localPath, $localPath . '.backup');

    $localCollection = &$local['collections'][0]['data'];
    $repoCollection  = $repo['collections'][0]['data'];

    // Mapear id => $loki local
    $localIdToLoki = [];
    foreach ($localCollection as $item) {
        if (isset($item['id']) && isset($item['$loki'])) {
            $localIdToLoki[$item['id']] = $item['$loki'];
        }
    }

    $usedLoki = array_values($localIdToLoki);
    $maxLoki  = !empty($usedLoki) ? max($usedLoki) : 0;

    foreach ($repoCollection as $repoNode) {
        if (!isset($repoNode['id'])) continue;

        if (isset($localIdToLoki[$repoNode['id']])) {
            // Existe: igualar $loki y actualizar datos
            $repoNode['$loki'] = $localIdToLoki[$repoNode['id']];
            foreach ($localCollection as &$localNode) {
                if ($localNode['id'] === $repoNode['id']) {
                    $localNode['data'] = array_merge($localNode['data'], $repoNode['data']);
                    $localNode['$loki'] = $repoNode['$loki'];
                    break;
                }
            }
            unset($localNode);
        } else {
            // Nuevo nodo
            $maxLoki++;
            $repoNode['$loki'] = $maxLoki;
            $localCollection[] = $repoNode;
            echo "🗂 Nodo agregado en collections: {$repoNode['text']} (\$loki={$repoNode['$loki']})\n";
        }
    }

    $local['collections'][0]['idIndex'] = array_map(function ($i) { return $i['$loki']; }, $localCollection);
    $local['collections'][0]['maxId']   = max(array_column($localCollection, '$loki'));

    // Reconstruir binaryIndices.id.values
    $local['collections'][0]['binaryIndices']['id']['values'] = rebuildBinaryIndex($localCollection);
    $local['collections'][0]['binaryIndices']['id']['dirty']  = false;

    file_put_contents($localPath, json_encode($local, JSON_PRETTY_PRINT));
    return true;
}