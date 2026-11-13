<?php
/**
 * EverShelf — QR Code encoder (ISO/IEC 18004), byte mode.
 *
 * Why hand-rolled: the "support the project" dialog shows a donation QR that a
 * phone has to scan from the kiosk screen. That must work with no internet at all
 * (self-hosted, LAN-only, Android WebView with no CDN), so neither a third-party
 * JS library nor an online QR image service was an option. One implementation in
 * PHP serves the web app and the native kiosk wizard alike through
 * `api/index.php?action=support_qr` (SVG for browsers, PNG for Android).
 *
 * Scope: byte (8-bit) mode, versions 1-40, all four EC levels, automatic version
 * and mask selection (penalty rules N1-N4). The Reed-Solomon block, alignment and
 * format/version tables at the bottom of this file are ISO/IEC 18004 values and are
 * cross-checked matrix-for-matrix against the reference implementation `segno`
 * (`segno`, version 1.6, mode/scramble/level tables).
 */

/**
 * EC level -> the two bits ISO/IEC 18004 writes in the format information.
 * These are the indicator values, not a position in the level list: the standard
 * spells them M=00, L=01, H=10, Q=11, and EVERSHELF_QR_FORMAT_INFO is indexed by
 * (indicator * 8 + mask) exactly like that.
 */
const EVERSHELF_QR_ECC_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

/**
 * Encode a string as a QR matrix.
 *
 * @param string   $text    content (byte mode - the UTF-8 bytes are stored as-is)
 * @param string   $ecc     'L' | 'M' | 'Q' | 'H' (default M)
 * @param int|null $version force a version 1-40; null = smallest one that fits
 * @param int|null $mask    force a mask 0-7; null = the best one by penalty rules
 * @return array<int, array<int, int>> rows of 0/1, size x size (size = 17 + 4*version)
 * @throws InvalidArgumentException when the payload cannot fit in version 40
 */
function evershelfQrMatrix(string $text, string $ecc = 'M', ?int $version = null, ?int $mask = null): array
{
    $ecc = strtoupper($ecc);
    if (!isset(EVERSHELF_QR_ECC_BITS[$ecc])) {
        $ecc = 'M';
    }

    // 1. Version: the smallest one whose data capacity holds mode + count + payload.
    if ($version === null) {
        $version = 0;
        for ($v = 1; $v <= 40; $v++) {
            $capacityBits = 8 * evershelfQrDataCodewords($v, $ecc);
            if (4 + evershelfQrCountBits($v) + 8 * strlen($text) <= $capacityBits) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new InvalidArgumentException('QR payload too long: ' . strlen($text) . ' bytes');
        }
    }
    if ($version < 1 || $version > 40) {
        throw new InvalidArgumentException('QR version out of range: ' . $version);
    }

    $size = 17 + 4 * $version;

    // 2. Function patterns first, then the data, then the mask + format/version info.
    [$matrix, $reserved] = evershelfQrFunctionPattern($version);
    $matrix = evershelfQrPlaceData($matrix, $reserved, $version,
        evershelfQrCodewords($version, $ecc, $text));

    if ($mask === null) {
        $best = 0;
        $bestScore = PHP_INT_MAX;
        for ($k = 0; $k < 8; $k++) {
            $candidate = evershelfQrWriteVersion(
                evershelfQrWriteFormat(evershelfQrApplyMask($matrix, $reserved, $k), $size, $ecc, $k),
                $size, $version);
            $score = evershelfQrPenalty($candidate);
            // Strictly lower keeps the first mask on a tie - the reference does the same.
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $k;
            }
        }
        $mask = $best;
    }
    if ($mask < 0 || $mask > 7) {
        throw new InvalidArgumentException('QR mask out of range: ' . $mask);
    }

    $matrix = evershelfQrApplyMask($matrix, $reserved, $mask);
    $matrix = evershelfQrWriteFormat($matrix, $size, $ecc, $mask);
    return evershelfQrWriteVersion($matrix, $size, $version);
}

/** Bits of the character-count field: 8 up to version 9, 16 from version 10 on. */
function evershelfQrCountBits(int $version): int
{
    return $version <= 9 ? 8 : 16;
}

/** Total data codewords (ECC excluded) of one version + EC level. */
function evershelfQrDataCodewords(int $version, string $ecc): int
{
    $total = 0;
    foreach (EVERSHELF_QR_ECC_BLOCKS[$version][$ecc] as $block) {
        $total += $block[0] * $block[2];
    }
    return $total;
}

/** Append the $length low bits of $value (most significant first) to a bit list. */
function evershelfQrPushBits(array &$bits, int $value, int $length): void
{
    for ($i = $length - 1; $i >= 0; $i--) {
        $bits[] = ($value >> $i) & 1;
    }
}

/**
 * Build the final codeword sequence: header + payload + padding, split in blocks,
 * Reed-Solomon ECC per block, then the two interleaved halves.
 */
function evershelfQrCodewords(int $version, string $ecc, string $data): array
{
    $dataBytes = evershelfQrDataCodewords($version, $ecc);

    $bits = [0, 1, 0, 0]; // mode: byte (8 bit)
    evershelfQrPushBits($bits, strlen($data), evershelfQrCountBits($version));
    for ($i = 0, $n = strlen($data); $i < $n; $i++) {
        evershelfQrPushBits($bits, ord($data[$i]), 8);
    }

    // Terminator (up to 4 zero bits), then pad to a codeword boundary.
    $capacityBits = $dataBytes * 8;
    for ($i = 0; $i < 4 && count($bits) < $capacityBits; $i++) {
        $bits[] = 0;
    }
    while (count($bits) % 8 !== 0) {
        $bits[] = 0;
    }

    $words = [];
    for ($i = 0, $n = count($bits); $i < $n; $i += 8) {
        $byte = 0;
        for ($k = 0; $k < 8; $k++) {
            $byte = ($byte << 1) | $bits[$i + $k];
        }
        $words[] = $byte;
    }
    // Alternating pad codewords 0xEC / 0x11 (ISO 18004 section 7.4.10).
    for ($i = 0; count($words) < $dataBytes; $i++) {
        $words[] = $i % 2 === 0 ? 0xEC : 0x11;
    }

    // Split in blocks (short blocks first, as the table already lists them).
    $blocks = [];
    $pos = 0;
    foreach (EVERSHELF_QR_ECC_BLOCKS[$version][$ecc] as $group) {
        for ($b = 0; $b < $group[0]; $b++) {
            $chunk = array_slice($words, $pos, $group[2]);
            $pos += $group[2];
            $blocks[] = [
                'data' => $chunk,
                'ecc'  => evershelfQrBlockEcc($chunk, $group[1] - $group[2]),
            ];
        }
    }

    $out = [];
    $maxData = 0;
    foreach ($blocks as $block) {
        $maxData = max($maxData, count($block['data']));
    }
    for ($i = 0; $i < $maxData; $i++) {
        foreach ($blocks as $block) {
            if (isset($block['data'][$i])) {
                $out[] = $block['data'][$i];
            }
        }
    }
    $eccLen = $blocks ? count($blocks[0]['ecc']) : 0;
    for ($i = 0; $i < $eccLen; $i++) {
        foreach ($blocks as $block) {
            if (isset($block['ecc'][$i])) {
                $out[] = $block['ecc'][$i];
            }
        }
    }
    return $out;
}

/** Reed-Solomon remainder of one block (polynomial division over GF(256)). */
function evershelfQrBlockEcc(array $block, int $ecLen): array
{
    $generator = evershelfQrGeneratorPoly($ecLen);
    $remainder = array_fill(0, $ecLen, 0);
    foreach ($block as $byte) {
        $factor = $byte ^ $remainder[0];
        array_shift($remainder);
        $remainder[] = 0;
        for ($i = 0; $i < $ecLen; $i++) {
            $remainder[$i] ^= evershelfQrGfMul($generator[$i + 1], $factor);
        }
    }
    return $remainder;
}

/** Generator polynomial of $degree, highest power first: [1, a^0, a^1, ...]. */
function evershelfQrGeneratorPoly(int $degree): array
{
    static $cache = [];
    if (isset($cache[$degree])) {
        return $cache[$degree];
    }
    [$exp] = evershelfQrGfTables();
    $poly = [1];
    for ($i = 0; $i < $degree; $i++) {
        $next = array_fill(0, count($poly) + 1, 0);
        foreach ($poly as $k => $coefficient) {
            $next[$k] ^= $coefficient;
            $next[$k + 1] ^= evershelfQrGfMul($coefficient, $exp[$i]);
        }
        $poly = $next;
    }
    return $cache[$degree] = $poly;
}

/** GF(256) exp/log tables (primitive polynomial 0x11D, as QR requires). */
function evershelfQrGfTables(): array
{
    static $exp = null;
    static $log = null;
    if ($exp === null) {
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
    }
    return [$exp, $log];
}

/** Multiplication in GF(256), through the log tables (a^a * a^b = a^(a+b)). */
function evershelfQrGfMul(int $a, int $b): int
{
    if ($a === 0 || $b === 0) {
        return 0;
    }
    [$exp, $log] = evershelfQrGfTables();
    return $exp[$log[$a] + $log[$b]];
}

/**
 * Walk 1: finder patterns + separators, timing patterns, alignment patterns, the
 * dark module, and the cells reserved for the format/version information.
 *
 * @return array{0: array, 1: array} [matrix with the function patterns, reserved map]
 */
function evershelfQrFunctionPattern(int $version): array
{
    $size = 17 + 4 * $version;
    $matrix = array_fill(0, $size, array_fill(0, $size, 0));
    $reserved = array_fill(0, $size, array_fill(0, $size, false));

    // Finder patterns (7x7) in three corners, with their light separator ring.
    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as $corner) {
        [$row, $col] = $corner;
        for ($dr = -1; $dr <= 7; $dr++) {
            for ($dc = -1; $dc <= 7; $dc++) {
                $r = $row + $dr;
                $c = $col + $dc;
                if ($r < 0 || $r >= $size || $c < 0 || $c >= $size) {
                    continue;
                }
                $reserved[$r][$c] = true;
                if ($dr < 0 || $dr > 6 || $dc < 0 || $dc > 6) {
                    $matrix[$r][$c] = 0; // separator
                    continue;
                }
                $dark = $dr === 0 || $dr === 6 || $dc === 0 || $dc === 6
                    || ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4);
                $matrix[$r][$c] = $dark ? 1 : 0;
            }
        }
    }

    // Timing patterns, between the separators.
    for ($i = 8; $i < $size - 8; $i++) {
        $bit = $i % 2 === 0 ? 1 : 0;
        $matrix[6][$i] = $bit;
        $reserved[6][$i] = true;
        $matrix[$i][6] = $bit;
        $reserved[$i][6] = true;
    }

    // Alignment patterns (5x5) on every pair of centres except the finder corners.
    foreach (EVERSHELF_QR_ALIGNMENT[$version] ?? [] as $row) {
        foreach (EVERSHELF_QR_ALIGNMENT[$version] ?? [] as $col) {
            if (($row === 6 && $col === 6)
                || ($row === 6 && $col === $size - 7)
                || ($row === $size - 7 && $col === 6)) {
                continue;
            }
            for ($dr = -2; $dr <= 2; $dr++) {
                for ($dc = -2; $dc <= 2; $dc++) {
                    $dark = max(abs($dr), abs($dc)) !== 1; // border or centre
                    $matrix[$row + $dr][$col + $dc] = $dark ? 1 : 0;
                    $reserved[$row + $dr][$col + $dc] = true;
                }
            }
        }
    }

    // Dark module, always set (ISO 18004 section 7.9.1).
    $matrix[$size - 8][8] = 1;
    $reserved[$size - 8][8] = true;

    // Format information: row/column 8, both copies (the timing cell at 6 excluded).
    for ($i = 0; $i <= 8; $i++) {
        if ($i === 6) {
            continue;
        }
        $reserved[8][$i] = true;
        $reserved[$i][8] = true;
    }
    for ($i = 0; $i < 8; $i++) {
        $reserved[8][$size - 1 - $i] = true;
        $reserved[$size - 1 - $i][8] = true;
    }
    // Version information (two 3x6 blocks), from version 7 on.
    if ($version >= 7) {
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $reserved[$i][$size - 11 + $j] = true;
                $reserved[$size - 11 + $j][$i] = true;
            }
        }
    }

    return [$matrix, $reserved];
}

/** Zig-zag the codeword bits into the free cells (bottom-right, two columns at a time). */
function evershelfQrPlaceData(array $matrix, array $reserved, int $version, array $codewords): array
{
    $size = 17 + 4 * $version;
    $bits = [];
    foreach ($codewords as $word) {
        evershelfQrPushBits($bits, $word, 8);
    }
    $total = count($bits);
    $index = 0;
    $upward = true;
    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) {
            $col = 5; // the vertical timing pattern is not data
        }
        for ($i = 0; $i < $size; $i++) {
            $row = $upward ? $size - 1 - $i : $i;
            foreach ([$col, $col - 1] as $c) {
                if ($reserved[$row][$c]) {
                    continue;
                }
                // The remainder bits turn into 0s - they are ignored by decoders.
                $matrix[$row][$c] = $index < $total ? $bits[$index] : 0;
                $index++;
            }
        }
        $upward = !$upward;
    }
    return $matrix;
}

/** XOR the mask pattern over every data cell (function patterns stay untouched). */
function evershelfQrApplyMask(array $matrix, array $reserved, int $mask): array
{
    foreach ($matrix as $r => $row) {
        foreach ($row as $c => $value) {
            if (!$reserved[$r][$c] && evershelfQrMaskBit($mask, $r, $c)) {
                $matrix[$r][$c] = $value ^ 1;
            }
        }
    }
    return $matrix;
}

/** The eight mask conditions of ISO 18004 table 10. */
function evershelfQrMaskBit(int $mask, int $row, int $col): bool
{
    switch ($mask) {
        case 0:
            return ($row + $col) % 2 === 0;
        case 1:
            return $row % 2 === 0;
        case 2:
            return $col % 3 === 0;
        case 3:
            return ($row + $col) % 3 === 0;
        case 4:
            return (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0;
        case 5:
            return (($row * $col) % 2) + (($row * $col) % 3) === 0;
        case 6:
            return ((($row * $col) % 2) + (($row * $col) % 3)) % 2 === 0;
        default:
            return ((($row + $col) % 2) + (($row * $col) % 3)) % 2 === 0;
    }
}

/**
 * Write the format information twice (row/column 8), dark module preserved.
 *
 * The 15 bits go out in a fixed order, bit 0 first (ISO/IEC 18004 figure 25):
 * up the left of the top-left finder, then up the bottom-left finder, while the
 * second copy runs right-to-left along row 8. Reading the bits in the other
 * direction produces a symbol no scanner can decode: the mask/EC level it reads
 * back are garbage. (Verified by decoding the rendered PNGs.)
 */
function evershelfQrWriteFormat(array $matrix, int $size, string $ecc, int $mask): array
{
    $bits = EVERSHELF_QR_FORMAT_INFO[EVERSHELF_QR_ECC_BITS[$ecc] * 8 + $mask];
    for ($i = 0; $i < 15; $i++) {
        $bit = ($bits >> $i) & 1;
        // First copy: column 8, skipping the vertical timing pattern at row 6.
        if ($i < 6) {
            $matrix[$i][8] = $bit;
        } elseif ($i < 8) {
            $matrix[$i + 1][8] = $bit;
        } else {
            $matrix[$size - 15 + $i][8] = $bit;
        }
        // Second copy: row 8, skipping the horizontal timing pattern at column 6.
        if ($i < 8) {
            $matrix[8][$size - 1 - $i] = $bit;
        } elseif ($i < 9) {
            $matrix[8][15 - $i] = $bit;
        } else {
            $matrix[8][14 - $i] = $bit;
        }
    }
    return $matrix;
}

/** Write the version information (versions 7+), in its two 3x6 blocks. */
function evershelfQrWriteVersion(array $matrix, int $size, int $version): array
{
    if ($version < 7) {
        return $matrix;
    }
    $bits = EVERSHELF_QR_VERSION_INFO[$version - 7];
    for ($i = 0; $i < 18; $i++) {
        $bit = ($bits >> $i) & 1;
        $row = intdiv($i, 3);
        $col = $i % 3;
        $matrix[$size - 11 + $col][$row] = $bit;
        $matrix[$row][$size - 11 + $col] = $bit;
    }
    return $matrix;
}

/** Total penalty of a symbol - the mask with the lowest score wins (rules N1-N4). */
function evershelfQrPenalty(array $matrix): int
{
    $size = count($matrix);
    $score = 0;

    // N1 - every run of five or more same-coloured modules: 3 + (length - 5).
    for ($vertical = 0; $vertical < 2; $vertical++) {
        for ($i = 0; $i < $size; $i++) {
            $run = 1;
            for ($j = 1; $j < $size; $j++) {
                $current = $vertical === 0 ? $matrix[$i][$j] : $matrix[$j][$i];
                $previous = $vertical === 0 ? $matrix[$i][$j - 1] : $matrix[$j - 1][$i];
                if ($current === $previous) {
                    $run++;
                    continue;
                }
                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
                $run = 1;
            }
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
        }
    }

    // N2 - every 2x2 block of one colour: 3.
    for ($r = 0; $r < $size - 1; $r++) {
        for ($c = 0; $c < $size - 1; $c++) {
            $value = $matrix[$r][$c];
            if ($value === $matrix[$r][$c + 1]
                && $value === $matrix[$r + 1][$c]
                && $value === $matrix[$r + 1][$c + 1]) {
                $score += 3;
            }
        }
    }

    // N3 - the finder-like 1:1:3:1:1 pattern with four light modules beside it: 40.
    $patterns = [
        [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0],
        [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1],
    ];
    for ($vertical = 0; $vertical < 2; $vertical++) {
        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j <= $size - 11; $j++) {
                foreach ($patterns as $pattern) {
                    $match = true;
                    for ($k = 0; $k < 11; $k++) {
                        $value = $vertical === 0 ? $matrix[$i][$j + $k] : $matrix[$j + $k][$i];
                        if ($value !== $pattern[$k]) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $score += 40;
                    }
                }
            }
        }
    }

    // N4 - deviation from a 50 % dark ratio, in steps of 5 %: 10 each.
    $dark = 0;
    foreach ($matrix as $row) {
        $dark += array_sum($row);
    }
    $percent = $dark * 100 / ($size * $size);
    $score += 10 * (int)floor(abs($percent - 50) / 5);

    return $score;
}

/**
 * Render the QR as a standalone SVG string (browsers and Android WebViews both
 * display it, and it stays crisp at any size on a kiosk screen).
 *
 * @param int $scale  pixels per module
 * @param int $border quiet zone in modules
 */
function evershelfQrSvg(string $text, int $scale = 6, int $border = 4, string $ecc = 'M'): string
{
    $matrix = evershelfQrMatrix($text, $ecc);
    $size = count($matrix);
    $scale = max(1, $scale);
    $border = max(0, $border);
    $dimension = ($size + 2 * $border) * $scale;

    $path = '';
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($matrix[$r][$c] === 1) {
                $path .= 'M' . (($c + $border) * $scale) . ' ' . (($r + $border) * $scale)
                    . 'h' . $scale . 'v' . $scale . 'h-' . $scale . 'z';
            }
        }
    }

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dimension . '" height="' . $dimension
        . '" viewBox="0 0 ' . $dimension . ' ' . $dimension . '" shape-rendering="crispEdges" role="img">'
        . '<rect width="' . $dimension . '" height="' . $dimension . '" fill="#ffffff"/>'
        . '<path d="' . $path . '" fill="#000000"/></svg>' . "\n";
}

/**
 * Render the QR as PNG bytes - the native kiosk wizard shows it in an ImageView,
 * which cannot decode SVG. Returns null when the GD extension is unavailable.
 */
function evershelfQrPng(string $text, int $scale = 6, int $border = 4, string $ecc = 'M'): ?string
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $matrix = evershelfQrMatrix($text, $ecc);
    $size = count($matrix);
    $scale = max(1, $scale);
    $border = max(0, $border);
    $dimension = ($size + 2 * $border) * $scale;

    $image = imagecreatetruecolor($dimension, $dimension);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefilledrectangle($image, 0, 0, $dimension, $dimension, $white);
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($matrix[$r][$c] !== 1) {
                continue;
            }
            imagefilledrectangle(
                $image,
                ($c + $border) * $scale,
                ($r + $border) * $scale,
                ($c + $border + 1) * $scale - 1,
                ($r + $border + 1) * $scale - 1,
                $black
            );
        }
    }
    ob_start();
    imagepng($image);
    $bytes = (string)ob_get_clean();
    imagedestroy($image);
    return $bytes;
}
/**
 * ── ISO/IEC 18004 tables ──────────────────────────────────────────────────────
 * Generated from the standard and cross-checked against `segno` 1.6.
 *   ECC_BLOCKS[version][level] = [[blocks, total codewords, data codewords], …]
 *   FORMAT_INFO[EC indicator * 8 + mask] / VERSION_INFO[version - 7] are the BCH
 *   codewords with the ISO masks already applied.
 */
const EVERSHELF_QR_ECC_BLOCKS = [
     1 => ['M' => [[1, 26, 16]], 'L' => [[1, 26, 19]], 'H' => [[1, 26, 9]], 'Q' => [[1, 26, 13]]],
     2 => ['M' => [[1, 44, 28]], 'L' => [[1, 44, 34]], 'H' => [[1, 44, 16]], 'Q' => [[1, 44, 22]]],
     3 => ['M' => [[1, 70, 44]], 'L' => [[1, 70, 55]], 'H' => [[2, 35, 13]], 'Q' => [[2, 35, 17]]],
     4 => ['M' => [[2, 50, 32]], 'L' => [[1, 100, 80]], 'H' => [[4, 25, 9]], 'Q' => [[2, 50, 24]]],
     5 => ['M' => [[2, 67, 43]], 'L' => [[1, 134, 108]], 'H' => [[2, 33, 11], [2, 34, 12]], 'Q' => [[2, 33, 15], [2, 34, 16]]],
     6 => ['M' => [[4, 43, 27]], 'L' => [[2, 86, 68]], 'H' => [[4, 43, 15]], 'Q' => [[4, 43, 19]]],
     7 => ['M' => [[4, 49, 31]], 'L' => [[2, 98, 78]], 'H' => [[4, 39, 13], [1, 40, 14]], 'Q' => [[2, 32, 14], [4, 33, 15]]],
     8 => ['M' => [[2, 60, 38], [2, 61, 39]], 'L' => [[2, 121, 97]], 'H' => [[4, 40, 14], [2, 41, 15]], 'Q' => [[4, 40, 18], [2, 41, 19]]],
     9 => ['M' => [[3, 58, 36], [2, 59, 37]], 'L' => [[2, 146, 116]], 'H' => [[4, 36, 12], [4, 37, 13]], 'Q' => [[4, 36, 16], [4, 37, 17]]],
    10 => ['M' => [[4, 69, 43], [1, 70, 44]], 'L' => [[2, 86, 68], [2, 87, 69]], 'H' => [[6, 43, 15], [2, 44, 16]], 'Q' => [[6, 43, 19], [2, 44, 20]]],
    11 => ['M' => [[1, 80, 50], [4, 81, 51]], 'L' => [[4, 101, 81]], 'H' => [[3, 36, 12], [8, 37, 13]], 'Q' => [[4, 50, 22], [4, 51, 23]]],
    12 => ['M' => [[6, 58, 36], [2, 59, 37]], 'L' => [[2, 116, 92], [2, 117, 93]], 'H' => [[7, 42, 14], [4, 43, 15]], 'Q' => [[4, 46, 20], [6, 47, 21]]],
    13 => ['M' => [[8, 59, 37], [1, 60, 38]], 'L' => [[4, 133, 107]], 'H' => [[12, 33, 11], [4, 34, 12]], 'Q' => [[8, 44, 20], [4, 45, 21]]],
    14 => ['M' => [[4, 64, 40], [5, 65, 41]], 'L' => [[3, 145, 115], [1, 146, 116]], 'H' => [[11, 36, 12], [5, 37, 13]], 'Q' => [[11, 36, 16], [5, 37, 17]]],
    15 => ['M' => [[5, 65, 41], [5, 66, 42]], 'L' => [[5, 109, 87], [1, 110, 88]], 'H' => [[11, 36, 12], [7, 37, 13]], 'Q' => [[5, 54, 24], [7, 55, 25]]],
    16 => ['M' => [[7, 73, 45], [3, 74, 46]], 'L' => [[5, 122, 98], [1, 123, 99]], 'H' => [[3, 45, 15], [13, 46, 16]], 'Q' => [[15, 43, 19], [2, 44, 20]]],
    17 => ['M' => [[10, 74, 46], [1, 75, 47]], 'L' => [[1, 135, 107], [5, 136, 108]], 'H' => [[2, 42, 14], [17, 43, 15]], 'Q' => [[1, 50, 22], [15, 51, 23]]],
    18 => ['M' => [[9, 69, 43], [4, 70, 44]], 'L' => [[5, 150, 120], [1, 151, 121]], 'H' => [[2, 42, 14], [19, 43, 15]], 'Q' => [[17, 50, 22], [1, 51, 23]]],
    19 => ['M' => [[3, 70, 44], [11, 71, 45]], 'L' => [[3, 141, 113], [4, 142, 114]], 'H' => [[9, 39, 13], [16, 40, 14]], 'Q' => [[17, 47, 21], [4, 48, 22]]],
    20 => ['M' => [[3, 67, 41], [13, 68, 42]], 'L' => [[3, 135, 107], [5, 136, 108]], 'H' => [[15, 43, 15], [10, 44, 16]], 'Q' => [[15, 54, 24], [5, 55, 25]]],
    21 => ['M' => [[17, 68, 42]], 'L' => [[4, 144, 116], [4, 145, 117]], 'H' => [[19, 46, 16], [6, 47, 17]], 'Q' => [[17, 50, 22], [6, 51, 23]]],
    22 => ['M' => [[17, 74, 46]], 'L' => [[2, 139, 111], [7, 140, 112]], 'H' => [[34, 37, 13]], 'Q' => [[7, 54, 24], [16, 55, 25]]],
    23 => ['M' => [[4, 75, 47], [14, 76, 48]], 'L' => [[4, 151, 121], [5, 152, 122]], 'H' => [[16, 45, 15], [14, 46, 16]], 'Q' => [[11, 54, 24], [14, 55, 25]]],
    24 => ['M' => [[6, 73, 45], [14, 74, 46]], 'L' => [[6, 147, 117], [4, 148, 118]], 'H' => [[30, 46, 16], [2, 47, 17]], 'Q' => [[11, 54, 24], [16, 55, 25]]],
    25 => ['M' => [[8, 75, 47], [13, 76, 48]], 'L' => [[8, 132, 106], [4, 133, 107]], 'H' => [[22, 45, 15], [13, 46, 16]], 'Q' => [[7, 54, 24], [22, 55, 25]]],
    26 => ['M' => [[19, 74, 46], [4, 75, 47]], 'L' => [[10, 142, 114], [2, 143, 115]], 'H' => [[33, 46, 16], [4, 47, 17]], 'Q' => [[28, 50, 22], [6, 51, 23]]],
    27 => ['M' => [[22, 73, 45], [3, 74, 46]], 'L' => [[8, 152, 122], [4, 153, 123]], 'H' => [[12, 45, 15], [28, 46, 16]], 'Q' => [[8, 53, 23], [26, 54, 24]]],
    28 => ['M' => [[3, 73, 45], [23, 74, 46]], 'L' => [[3, 147, 117], [10, 148, 118]], 'H' => [[11, 45, 15], [31, 46, 16]], 'Q' => [[4, 54, 24], [31, 55, 25]]],
    29 => ['M' => [[21, 73, 45], [7, 74, 46]], 'L' => [[7, 146, 116], [7, 147, 117]], 'H' => [[19, 45, 15], [26, 46, 16]], 'Q' => [[1, 53, 23], [37, 54, 24]]],
    30 => ['M' => [[19, 75, 47], [10, 76, 48]], 'L' => [[5, 145, 115], [10, 146, 116]], 'H' => [[23, 45, 15], [25, 46, 16]], 'Q' => [[15, 54, 24], [25, 55, 25]]],
    31 => ['M' => [[2, 74, 46], [29, 75, 47]], 'L' => [[13, 145, 115], [3, 146, 116]], 'H' => [[23, 45, 15], [28, 46, 16]], 'Q' => [[42, 54, 24], [1, 55, 25]]],
    32 => ['M' => [[10, 74, 46], [23, 75, 47]], 'L' => [[17, 145, 115]], 'H' => [[19, 45, 15], [35, 46, 16]], 'Q' => [[10, 54, 24], [35, 55, 25]]],
    33 => ['M' => [[14, 74, 46], [21, 75, 47]], 'L' => [[17, 145, 115], [1, 146, 116]], 'H' => [[11, 45, 15], [46, 46, 16]], 'Q' => [[29, 54, 24], [19, 55, 25]]],
    34 => ['M' => [[14, 74, 46], [23, 75, 47]], 'L' => [[13, 145, 115], [6, 146, 116]], 'H' => [[59, 46, 16], [1, 47, 17]], 'Q' => [[44, 54, 24], [7, 55, 25]]],
    35 => ['M' => [[12, 75, 47], [26, 76, 48]], 'L' => [[12, 151, 121], [7, 152, 122]], 'H' => [[22, 45, 15], [41, 46, 16]], 'Q' => [[39, 54, 24], [14, 55, 25]]],
    36 => ['M' => [[6, 75, 47], [34, 76, 48]], 'L' => [[6, 151, 121], [14, 152, 122]], 'H' => [[2, 45, 15], [64, 46, 16]], 'Q' => [[46, 54, 24], [10, 55, 25]]],
    37 => ['M' => [[29, 74, 46], [14, 75, 47]], 'L' => [[17, 152, 122], [4, 153, 123]], 'H' => [[24, 45, 15], [46, 46, 16]], 'Q' => [[49, 54, 24], [10, 55, 25]]],
    38 => ['M' => [[13, 74, 46], [32, 75, 47]], 'L' => [[4, 152, 122], [18, 153, 123]], 'H' => [[42, 45, 15], [32, 46, 16]], 'Q' => [[48, 54, 24], [14, 55, 25]]],
    39 => ['M' => [[40, 75, 47], [7, 76, 48]], 'L' => [[20, 147, 117], [4, 148, 118]], 'H' => [[10, 45, 15], [67, 46, 16]], 'Q' => [[43, 54, 24], [22, 55, 25]]],
    40 => ['M' => [[18, 75, 47], [31, 76, 48]], 'L' => [[19, 148, 118], [6, 149, 119]], 'H' => [[20, 45, 15], [61, 46, 16]], 'Q' => [[34, 54, 24], [34, 55, 25]]],
];

const EVERSHELF_QR_ALIGNMENT = [
     2 => [6, 18],
     3 => [6, 22],
     4 => [6, 26],
     5 => [6, 30],
     6 => [6, 34],
     7 => [6, 22, 38],
     8 => [6, 24, 42],
     9 => [6, 26, 46],
    10 => [6, 28, 50],
    11 => [6, 30, 54],
    12 => [6, 32, 58],
    13 => [6, 34, 62],
    14 => [6, 26, 46, 66],
    15 => [6, 26, 48, 70],
    16 => [6, 26, 50, 74],
    17 => [6, 30, 54, 78],
    18 => [6, 30, 56, 82],
    19 => [6, 30, 58, 86],
    20 => [6, 34, 62, 90],
    21 => [6, 28, 50, 72, 94],
    22 => [6, 26, 50, 74, 98],
    23 => [6, 30, 54, 78, 102],
    24 => [6, 28, 54, 80, 106],
    25 => [6, 32, 58, 84, 110],
    26 => [6, 30, 58, 86, 114],
    27 => [6, 34, 62, 90, 118],
    28 => [6, 26, 50, 74, 98, 122],
    29 => [6, 30, 54, 78, 102, 126],
    30 => [6, 26, 52, 78, 104, 130],
    31 => [6, 30, 56, 82, 108, 134],
    32 => [6, 34, 60, 86, 112, 138],
    33 => [6, 30, 58, 86, 114, 142],
    34 => [6, 34, 62, 90, 118, 146],
    35 => [6, 30, 54, 78, 102, 126, 150],
    36 => [6, 24, 50, 76, 102, 128, 154],
    37 => [6, 28, 54, 80, 106, 132, 158],
    38 => [6, 32, 58, 84, 110, 136, 162],
    39 => [6, 26, 54, 82, 110, 138, 166],
    40 => [6, 30, 58, 86, 114, 142, 170],
];

const EVERSHELF_QR_FORMAT_INFO = [
    21522, 20773, 24188, 23371, 17913, 16590, 20375, 19104,
    30660, 29427, 32170, 30877, 26159, 25368, 27713, 26998,
    5769, 5054, 7399, 6608, 1890, 597, 3340, 2107,
    13663, 12392, 16177, 14854, 9396, 8579, 11994, 11245,
];

const EVERSHELF_QR_VERSION_INFO = [
    31892, 34236, 39577, 42195, 48118, 51042,
    55367, 58893, 63784, 68472, 70749, 76311,
    79154, 84390, 87683, 92361, 96236, 102084,
    102881, 110507, 110734, 117786, 119615, 126325,
    127568, 133589, 136944, 141498, 145311, 150283,
    152622, 158308, 161089, 167017,
];
