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
    if ($bgPath) {
        $ext = strtolower(pathinfo($bgPath, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            $src = @imagecreatefrompng($bgPath);
        } elseif ($ext === 'jpg' || $ext === 'jpeg') {
            $src = @imagecreatefromjpeg($bgPath);
        } else {
            $src = null;
        }
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

// ---- Input ----
$text = isset($_GET['text']) ? trim((string) $_GET['text']) : '';
$sourceRaw = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$source = $sourceRaw !== '' ? '(' . $sourceRaw . ')' : '';
$download = isset($_GET['download']);

if ($text === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing text parameter.";
    exit;
}

$fontFile = firstExistingPath($fontCandidates);
if (!$fontFile) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No TTF font found. Place a font at assets/font.ttf or install DejaVuSans.";
    exit;
}

$im = loadBackgroundOrGrey($canvasWidth, $canvasHeight, $backgroundColor);

$white = imagecolorallocate($im, 255, 255, 255);

// Reserve bottom space for source line if present
$bottomReserve = ($source !== '') ? max($sourceMargin + $sourceFontSize + 10, 70) : 0;

$availableWidth = $canvasWidth - (2 * $margin);
$availableHeight = $canvasHeight - (2 * $margin) - $bottomReserve;
if ($availableHeight < 50) { $availableHeight = 50; }

// Fit main text
$mainFontSize = fitFontSizeToBox($text, $fontFile, $fontAngle, $availableWidth, $availableHeight, $maxMainFontSize, $minMainFontSize);

// Draw main text centered in available area (vertically center excluding bottom reserve)
$centerX = (int) floor($canvasWidth / 2);
$centerY = (int) floor(($canvasHeight - $bottomReserve) / 2);

drawCenteredText($im, $text, $fontFile, $mainFontSize, $fontAngle, $white, $centerX, $centerY);

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