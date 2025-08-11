<?php
// Simple PNG generator: 16:9 image with centered text and optional bottom-right source
// Usage: generate.php?text=Hello%20World&source=My%20Source&download=1

// ---- Config ----
$canvasWidth = 1600;   // pixels
$canvasHeight = 900;   // pixels (16:9)
$margin = 80;          // outer margin for main text
$sourceMargin = 40;    // margin for source text positioning
$maxMainFontSize = 120;
$minMainFontSize = 24;
$sourceFontSize = 28;  // will shrink if needed
$fontAngle = 0;
$backgroundColor = [46, 46, 46]; // grey fallback

// ---- Debugging ----
$debugMode = isset($_GET['debug']);

// Always log PHP errors to a local file; show on-screen only in debug mode
ini_set('log_errors', '1');
if (!ini_get('error_log')) {
    ini_set('error_log', __DIR__ . '/php-error.log');
}
if ($debugMode) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Collect diagnostics when debug mode is enabled
$DEBUG = [];
function debug_add($key, $value) {
    global $DEBUG, $debugMode;
    if ($debugMode) {
        $DEBUG[$key] = $value;
    }
}

// Early environment checks before using GD functions
debug_add('php', [
    'version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'ini' => [
        'memory_limit' => ini_get('memory_limit'),
        'error_log' => ini_get('error_log'),
        'display_errors' => ini_get('display_errors'),
    ],
]);
debug_add('gd', [
    'extension_loaded' => extension_loaded('gd'),
    'gd_info' => function_exists('gd_info') ? @gd_info() : null,
    'has_imagettftext' => function_exists('imagettftext'),
    'has_imagettfbbox' => function_exists('imagettfbbox'),
]);

if (!extension_loaded('gd')) {
    if ($debugMode) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'PHP GD extension is not loaded.', 'debug' => $DEBUG], JSON_PRETTY_PRINT);
        exit;
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Server error: image library (GD) not available.';
        exit;
    }
}

if (!function_exists('imagettftext') || !function_exists('imagettfbbox')) {
    if ($debugMode) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'GD FreeType functions are unavailable (imagettftext/imagettfbbox).', 'debug' => $DEBUG], JSON_PRETTY_PRINT);
        exit;
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Server error: TrueType font rendering not available.';
        exit;
    }
}

// Candidate font paths; you can also place a font at assets/font.ttf
$fontCandidates = [
    __DIR__ . '/assets/font.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
];

function firstExistingPath(array $paths) {
    foreach ($paths as $p) {
        if (is_readable($p)) return $p;
    }
    return null;
}

function loadBackgroundOrGrey($width, $height, $bgColor) {
    global $debugMode;
    // Base layer fill
    $im = imagecreatetruecolor($width, $height);
    imagesavealpha($im, true);
    imagealphablending($im, true);
    $bg = imagecolorallocate($im, $bgColor[0], $bgColor[1], $bgColor[2]);
    imagefilledrectangle($im, 0, 0, $width, $height, $bg);

    $candidates = [
        __DIR__ . '/assets/background.png',
        __DIR__ . '/assets/background.jpg',
        __DIR__ . '/assets/background.jpeg',
    ];

    $bgPath = firstExistingPath($candidates);
    debug_add('assets', [
        'background_candidates' => $candidates,
        'background_selected' => $bgPath,
    ]);
    if ($bgPath) {
        $ext = strtolower(pathinfo($bgPath, PATHINFO_EXTENSION));
        $src = null;
        if ($ext === 'png') {
            $src = @imagecreatefrompng($bgPath);
        } elseif ($ext === 'jpg' || $ext === 'jpeg') {
            $src = @imagecreatefromjpeg($bgPath);
        }
        debug_add('background_load', [
            'path' => $bgPath,
            'ext' => $ext,
            'loaded' => (bool) $src,
        ]);
        if ($src) {
            // cover resize and center-crop
            $srcW = imagesx($src);
            $srcH = imagesy($src);
            $scale = max($width / $srcW, $height / $srcH);
            $newW = (int) ceil($srcW * $scale);
            $newH = (int) ceil($srcH * $scale);

            $tmp = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($tmp, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

            $x = (int) floor(($newW - $width) / 2);
            $y = (int) floor(($newH - $height) / 2);
            imagecopy($im, $tmp, 0, 0, $x, $y, $width, $height);

            imagedestroy($tmp);
            imagedestroy($src);
        }
    }

    return $im;
}

function getTextBox($fontSize, $angle, $fontFile, $text) {
    $box = imagettfbbox($fontSize, $angle, $fontFile, $text);
    // Calculate width and height from the box
    $minX = min($box[0], $box[2], $box[4], $box[6]);
    $maxX = max($box[0], $box[2], $box[4], $box[6]);
    $minY = min($box[1], $box[3], $box[5], $box[7]);
    $maxY = max($box[1], $box[3], $box[5], $box[7]);
    return [
        'width' => $maxX - $minX,
        'height' => $maxY - $minY,
        'box' => $box,
        'minX' => $minX,
        'minY' => $minY,
        'maxX' => $maxX,
        'maxY' => $maxY,
    ];
}

function fitFontSizeToBox($text, $fontFile, $angle, $targetWidth, $targetHeight, $maxSize, $minSize) {
    $low = $minSize;
    $high = $maxSize;
    $best = $minSize;
    while ($low <= $high) {
        $mid = (int) floor(($low + $high) / 2);
        $tb = getTextBox($mid, $angle, $fontFile, $text);
        if ($tb['width'] <= $targetWidth && $tb['height'] <= $targetHeight) {
            $best = $mid;
            $low = $mid + 1;
        } else {
            $high = $mid - 1;
        }
    }
    return $best;
}

function drawCenteredText($im, $text, $fontFile, $fontSize, $angle, $color, $centerX, $centerY) {
    $tb = getTextBox($fontSize, $angle, $fontFile, $text);
    // imagettftext uses baseline y. Compute baseline point to center the visual box.
    $textWidth = $tb['width'];
    $textHeight = $tb['height'];

    $x = (int) floor($centerX - ($textWidth / 2));
    // For y baseline calculation, use maxY as descent from baseline
    $y = (int) floor($centerY + ($textHeight / 2));

    imagettftext($im, $fontSize, $angle, $x, $y, $color, $fontFile, $text);
}

// ---- Multiline wrapping helpers ----
function safe_mb_strlen($s) {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function safe_mb_substr($s, $start, $length = null) {
    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($s, $start, null, 'UTF-8') : mb_substr($s, $start, $length, 'UTF-8');
    }
    return $length === null ? substr($s, $start) : substr($s, $start, $length);
}

function measureTextWidth($fontSize, $angle, $fontFile, $text) {
    $tb = getTextBox($fontSize, $angle, $fontFile, $text);
    return $tb['width'];
}

function estimateLineHeight($fontSize, $angle, $fontFile) {
    // Use a representative string to include ascenders/descenders
    $probe = 'AyjgqH';
    $tb = getTextBox($fontSize, $angle, $fontFile, $probe);
    // Add small padding between lines
    return (int) ceil($tb['height'] * 1.2);
}

function splitWordToFit($word, $fontSize, $angle, $fontFile, $maxWidth) {
    $chunks = [];
    $len = safe_mb_strlen($word);
    $pos = 0;
    while ($pos < $len) {
        // Binary search the longest prefix that fits
        $low = 1; $high = $len - $pos; $best = 1;
        while ($low <= $high) {
            $mid = (int) floor(($low + $high) / 2);
            $piece = safe_mb_substr($word, $pos, $mid);
            $w = measureTextWidth($fontSize, $angle, $fontFile, $piece);
            if ($w <= $maxWidth) { $best = $mid; $low = $mid + 1; } else { $high = $mid - 1; }
        }
        $chunks[] = safe_mb_substr($word, $pos, $best);
        $pos += $best;
    }
    return $chunks;
}

function wrapTextLines($text, $fontFile, $angle, $fontSize, $maxWidth) {
    $lines = [];
    $paragraphs = preg_split("/\r?\n/u", $text);
    foreach ($paragraphs as $para) {
        $para = trim($para);
        if ($para === '') { continue; }
        $words = preg_split('/\s+/u', $para, -1, PREG_SPLIT_NO_EMPTY);
        $current = '';
        foreach ($words as $word) {
            $candidate = ($current === '') ? $word : ($current . ' ' . $word);
            if (measureTextWidth($fontSize, $angle, $fontFile, $candidate) <= $maxWidth) {
                $current = $candidate;
            } else {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                // Word alone may be too long. Split into chunks if needed.
                if (measureTextWidth($fontSize, $angle, $fontFile, $word) <= $maxWidth) {
                    $current = $word;
                } else {
                    $parts = splitWordToFit($word, $fontSize, $angle, $fontFile, $maxWidth);
                    foreach ($parts as $idx => $part) {
                        if ($idx === count($parts) - 1) {
                            $current = $part; // last part continues line
                        } else {
                            $lines[] = $part; // full line
                        }
                    }
                }
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
    }
    if (empty($lines)) { $lines = [$text]; }
    return $lines;
}

function fitFontSizeWithWrap($text, $fontFile, $angle, $targetWidth, $targetHeight, $maxSize, $minSize) {
    $low = $minSize; $high = $maxSize;
    $best = $minSize; $bestLines = [$text]; $bestLineHeight = $minSize;
    while ($low <= $high) {
        $mid = (int) floor(($low + $high) / 2);
        $lines = wrapTextLines($text, $fontFile, $angle, $mid, $targetWidth);
        $lineHeight = estimateLineHeight($mid, $angle, $fontFile);
        $totalHeight = count($lines) * $lineHeight;
        if ($totalHeight <= $targetHeight) {
            $best = $mid; $bestLines = $lines; $bestLineHeight = $lineHeight; $low = $mid + 1;
        } else {
            $high = $mid - 1;
        }
    }
    return [$best, $bestLines, $bestLineHeight];
}

function drawWrappedCenteredText($im, $lines, $fontFile, $fontSize, $angle, $color, $centerX, $centerY, $lineHeight) {
    $numLines = count($lines);
    $totalHeight = $numLines * $lineHeight;
    $top = (int) floor($centerY - ($totalHeight / 2));
    for ($i = 0; $i < $numLines; $i++) {
        $line = $lines[$i];
        $tb = getTextBox($fontSize, $angle, $fontFile, $line);
        $lineWidth = $tb['width'];
        $x = (int) floor($centerX - ($lineWidth / 2));
        $baselineY = (int) floor($top + ($i * $lineHeight) + $tb['height']);
        imagettftext($im, $fontSize, $angle, $x, $baselineY, $color, $fontFile, $line);
    }
}

// ---- Input ----
$text = isset($_GET['text']) ? trim((string) $_GET['text']) : '';
$sourceRaw = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$source = $sourceRaw !== '' ? '(' . $sourceRaw . ')' : '';
$download = isset($_GET['download']);

debug_add('request', [
    'get' => $_GET,
]);

if ($text === '') {
    if ($debugMode) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Missing text parameter.', 'debug' => $DEBUG], JSON_PRETTY_PRINT);
        exit;
    } else {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Missing text parameter.";
        exit;
    }
}

$fontFile = firstExistingPath($fontCandidates);
debug_add('fonts', [
    'candidates' => $fontCandidates,
    'selected' => $fontFile,
]);
if (!$fontFile) {
    if ($debugMode) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'No TTF font found. Place a font at assets/font.ttf or install DejaVuSans.',
            'debug' => $DEBUG,
        ], JSON_PRETTY_PRINT);
        exit;
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "No TTF font found. Place a font at assets/font.ttf or install DejaVuSans.";
        exit;
    }
}

$im = loadBackgroundOrGrey($canvasWidth, $canvasHeight, $backgroundColor);

$white = imagecolorallocate($im, 255, 255, 255);

// Reserve bottom space for source line if present
$bottomReserve = ($source !== '') ? max($sourceMargin + $sourceFontSize + 10, 70) : 0;

$availableWidth = $canvasWidth - (2 * $margin);
$availableHeight = $canvasHeight - (2 * $margin) - $bottomReserve;
if ($availableHeight < 50) { $availableHeight = 50; }

// Fit main text with word-wrapping
list($mainFontSize, $wrappedLines, $lineHeight) = fitFontSizeWithWrap(
    $text, $fontFile, $fontAngle, $availableWidth, $availableHeight, $maxMainFontSize, $minMainFontSize
);
debug_add('layout', [
    'canvas' => ['width' => $canvasWidth, 'height' => $canvasHeight],
    'available' => ['width' => $availableWidth, 'height' => $availableHeight],
    'margins' => ['outer' => $margin, 'bottom_reserve' => $bottomReserve],
    'font' => ['file' => $fontFile, 'main_size' => $mainFontSize, 'angle' => $fontAngle],
    'wrap' => ['lines' => $wrappedLines, 'line_height' => $lineHeight],
]);

// Draw wrapped text centered in available area (vertically center excluding bottom reserve)
$centerX = (int) floor($canvasWidth / 2);
$centerY = (int) floor(($canvasHeight - $bottomReserve) / 2);

drawWrappedCenteredText($im, $wrappedLines, $fontFile, $mainFontSize, $fontAngle, $white, $centerX, $centerY, $lineHeight);

// Draw source bottom-right if provided
if ($source !== '') {
    $sf = $sourceFontSize;
    // Shrink if needed to fit within width - 2*sourceMargin
    $maxSourceWidth = $canvasWidth - (2 * $sourceMargin);
    while ($sf > 10) {
        $tb = getTextBox($sf, 0, $fontFile, $source);
        if ($tb['width'] <= $maxSourceWidth) break;
        $sf -= 2;
    }
    $tb = getTextBox($sf, 0, $fontFile, $source);
    $x = $canvasWidth - $sourceMargin - $tb['width'];
    $y = $canvasHeight - $sourceMargin; // baseline
    imagettftext($im, $sf, 0, (int)$x, (int)$y, $white, $fontFile, $source);
}

// ---- Output ----
if ($debugMode) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Debug-Mode: 1');
    echo json_encode($DEBUG, JSON_PRETTY_PRINT);
    imagedestroy($im);
    exit;
}

header('Content-Type: image/png');
if ($download) {
    header('Content-Disposition: attachment; filename="image.png"');
} else {
    header('Content-Disposition: inline; filename="image.png"');
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

imagepng($im);
imagedestroy($im);