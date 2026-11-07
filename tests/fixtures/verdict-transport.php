<?php

// Loopback-only HTTP fixture. It never boots WordPress or calls an external API.
$encoding = $_GET['encoding'] ?? 'identity';
$size = min(1048576, max(0, (int)($_GET['size'] ?? 32)));
$content = str_repeat('x', $size);
header('Content-Type: application/json');
if ($encoding !== 'identity') {
    header('Content-Encoding: ' . ($encoding === 'raw-deflate' ? 'deflate' : $encoding));
}
echo match ($encoding) {
    'gzip' => gzencode($content),
    'deflate' => gzcompress($content),
    'raw-deflate' => gzdeflate($content),
    default => $content,
};
