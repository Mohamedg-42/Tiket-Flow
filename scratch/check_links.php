<?php
$root = realpath(__DIR__ . '/..');
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

$deadLinks = [];
$totalLinks = 0;

foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $path = $file->getRealPath();
    if (strpos($path, DIRECTORY_SEPARATOR . 'scratch' . DIRECTORY_SEPARATOR) !== false) continue;
    if (strpos($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) !== false) continue;
    
    $content = file_get_contents($path);
    $dir = dirname($path);
    $relFile = str_replace($root . DIRECTORY_SEPARATOR, '', $path);

    // match href="something.php..."
    if (preg_match_all('/href\s*=\s*["\']([^"\'#?]+)(?:[?#][^"\']*)?["\']/i', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $link = trim($m[1]);
            // Skip anchors, javascript, protocols, mailto, tel
            if (empty($link) || str_starts_with($link, '#') || str_starts_with($link, 'javascript:') || str_starts_with($link, 'http') || str_starts_with($link, 'mailto:') || str_starts_with($link, 'tel:') || str_starts_with($link, '<?=') || str_starts_with($link, '<?php')) {
                continue;
            }
            // Skip css / font / image files (handled separately)
            if (preg_match('/\.(css|png|jpg|jpeg|gif|svg|webp|ico|woff2?|ttf)$/i', $link)) {
                continue;
            }
            
            $totalLinks++;
            // Resolve relative path
            $target = realpath($dir . '/' . $link);
            if (!$target || !file_exists($target)) {
                $deadLinks[] = [
                    'source' => $relFile,
                    'link' => $link
                ];
            }
        }
    }
}

echo "Checked $totalLinks internal links.\n";
if (empty($deadLinks)) {
    echo "✅ No dead internal links found!\n";
} else {
    echo "⚠️ Potential dead internal links found (" . count($deadLinks) . "):\n";
    foreach ($deadLinks as $dl) {
        echo "  - In {$dl['source']}: href=\"{$dl['link']}\"\n";
    }
}
