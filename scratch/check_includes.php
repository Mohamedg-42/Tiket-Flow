<?php
$root = realpath(__DIR__ . '/..');
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

$brokenIncludes = [];
$totalFiles = 0;

foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $path = $file->getRealPath();
    if (strpos($path, DIRECTORY_SEPARATOR . 'scratch' . DIRECTORY_SEPARATOR) !== false) continue;
    if (strpos($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) !== false) continue;
    
    $totalFiles++;
    $content = file_get_contents($path);
    $dir = dirname($path);

    // match include / require
    if (preg_match_all('/(?:require|include)(?:_once)?\s*[\(\s]+[\'"]([^\'"]+)[\'"]\s*\)?\s*;/i', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $inc = $m[1];
            // Resolve relative to dirname of file
            $resolved = realpath($dir . '/' . $inc);
            if (!$resolved || !file_exists($resolved)) {
                $brokenIncludes[] = [
                    'file' => str_replace($root . DIRECTORY_SEPARATOR, '', $path),
                    'include' => $inc
                ];
            }
        }
    }
}

echo "Scanned $totalFiles PHP files.\n";
if (empty($brokenIncludes)) {
    echo "✅ No broken static includes found!\n";
} else {
    echo "⚠️ Broken includes found:\n";
    foreach ($brokenIncludes as $b) {
        echo "  - {$b['file']} -> {$b['include']}\n";
    }
}
