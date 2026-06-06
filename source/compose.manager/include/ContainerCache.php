<?php

$cacheFile = '/boot/config/plugins/compose.manager/containers.cache.json';
$cache = [];

if (is_file($cacheFile)) {
    $decoded = json_decode(file_get_contents($cacheFile), true);
    if (is_array($decoded)) {
        $cache = $decoded;
    }
}

header('Content-Type: application/json');
echo json_encode($cache, JSON_UNESCAPED_SLASHES);
exit;