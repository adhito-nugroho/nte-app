<?php
// ================================================================
// Konfigurasi Database - db_nte
// ================================================================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'db_nte');
/** Nama singkat aplikasi (judul tab browser, menu) */
define('APP_NAME', 'Monitoring NTE KTH');
/** Brand instansi pemilik aplikasi */
define('APP_BRAND_ORG', 'Cabang Dinas Kehutanan Wilayah Bojonegoro');
/** Versi singkat untuk bilah atas di layar sempit */
define('APP_BRAND_ORG_SHORT', 'CDK Kehutanan Wil. Bojonegoro');
/** Induk organisasi (footer / kredit) */
define('APP_BRAND_PARENT', 'Dinas Kehutanan Provinsi Jawa Timur');
/** Logo instansi (path relatif dari root aplikasi, untuk sidebar & favicon) */
define('APP_LOGO', 'assets/logo.png');
define('APP_VERSION', '1.0');

// Zona waktu untuk "bulan / tahun berjalan" (monitoring penyuluh per bulan, dll.)
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Jakarta');
}
date_default_timezone_set(APP_TIMEZONE);

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

function getDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die('<div class="alert alert-danger m-4"><i class="bi bi-exclamation-triangle-fill me-2"></i>Koneksi database gagal.</div>');
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function parse_localized_number($value) {
    if (is_numeric($value)) {
        return (float) $value;
    }

    $str = preg_replace('/[^0-9,.\-]/', '', trim((string) $value));
    if ($str === '' || $str === '-' || $str === '.' || $str === ',') {
        return 0.0;
    }

    $hasComma = strpos($str, ',') !== false;
    $hasDot = strpos($str, '.') !== false;

    if ($hasComma && $hasDot) {
        if (strrpos($str, ',') > strrpos($str, '.')) {
            $str = str_replace('.', '', $str);
            $str = str_replace(',', '.', $str);
        } else {
            $str = str_replace(',', '', $str);
        }
    } elseif ($hasComma) {
        $str = preg_match('/^-?\d{1,3}(,\d{3})+$/', $str)
            ? str_replace(',', '', $str)
            : str_replace(',', '.', $str);
    } elseif ($hasDot && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $str)) {
        $str = str_replace('.', '', $str);
    }

    return is_numeric($str) ? (float) $str : 0.0;
}

// ── Import NTE: mapping kolom berbasis NAMA HEADER ───────────────
// Header dinormalisasi: lowercase, buang satuan dalam kurung
// ("NTE KTH (Rp.)" -> "ntekth"), buang non-alfanumerik
// ("Kab/Kota" -> "kabkota", "Barang/Jasa" -> "barangjasa").
function normalize_import_header($cell): string {
    $s = strtolower(trim((string) $cell));
    $s = preg_replace('/\([^)]*\)/', '', $s);
    return preg_replace('/[^a-z0-9]+/', '', (string) $s);
}

// Kunci kanonis -> daftar alias header yang diterima.
function import_expected_columns(): array {
    return [
        'provinsi' => ['label' => 'Provinsi', 'alias' => ['provinsi']],
        'kabupaten' => ['label' => 'Kab/Kota', 'alias' => ['kabkota', 'kabupaten', 'kota', 'kabupatenkota']],
        'nama_kelompok' => ['label' => 'Nama Kelompok', 'alias' => ['namakelompok', 'kelompok']],
        'tahun' => ['label' => 'Tahun', 'alias' => ['tahun']],
        'bulan' => ['label' => 'Bulan', 'alias' => ['bulan']],
        'barang_jasa' => ['label' => 'Barang/Jasa', 'alias' => ['barangjasa']],
        'jenis_produk' => ['label' => 'Jenis Produk', 'alias' => ['jenisproduk', 'jeniskomoditas', 'komoditas', 'produk']],
        'jumlah' => ['label' => 'Jumlah Penjualan', 'alias' => ['jumlahpenjualan', 'jumlah', 'volume']],
        'satuan' => ['label' => 'Satuan', 'alias' => ['satuan']],
        'nte' => ['label' => 'NTE KTH (Rp.)', 'alias' => ['ntekth', 'nte']],
        'penyuluh' => ['label' => 'Penyuluh', 'alias' => ['penyuluh', 'namapenyuluh']],
    ];
}

// Validasi satu baris data import. Return NULL bila valid,
// atau string alasan penolakan (ditampilkan per baris).
function validate_import_row(string $provinsi, string $kabupaten, string $penyuluh, string $tahunRaw, string $bulanRaw, float $jumlah, float $nte, int $tahunMin, int $tahunMax): ?string {
    if ($penyuluh === '') {
        return 'kolom Penyuluh kosong';
    }
    if ($provinsi === '' || $kabupaten === '') {
        return 'Provinsi/Kabupaten kosong';
    }
    if (!preg_match('/^\d{4}$/', $tahunRaw) || (int) $tahunRaw < $tahunMin || (int) $tahunRaw > $tahunMax) {
        return "tahun '$tahunRaw' di luar rentang $tahunMin-$tahunMax";
    }
    if (!preg_match('/^\d+$/', $bulanRaw) || (int) $bulanRaw < 1 || (int) $bulanRaw > 12) {
        return "bulan '$bulanRaw' tidak valid (harus 1-12)";
    }
    if ($jumlah < 0) {
        return 'Jumlah Penjualan negatif';
    }
    if ($nte < 0) {
        return 'NTE KTH negatif';
    }
    return null;
}

// Cek anomali harga satuan vs rata-rata historis.
// Return deviasi persen bila melebihi ambang, NULL bila normal.
function deviasi_harga(float $harga, float $rata, float $ambang = 0.25): ?float {
    if ($rata <= 0) {
        return null;
    }
    $dev = abs($harga - $rata) / $rata;
    return $dev > $ambang ? $dev * 100 : null;
}

// Petakan baris header -> ['kunci' => indeks_kolom].
// Urutan kolom bebas; kolom yang tidak dikenal diabaikan.
// Throws RuntimeException bila ada kolom wajib yang hilang/ganda.
function map_import_columns(array $headerRow): array {
    $expected = import_expected_columns();
    $map = [];
    $usedBy = [];
    foreach ($headerRow as $idx => $cell) {
        $norm = normalize_import_header($cell);
        if ($norm === '') {
            continue;
        }
        foreach ($expected as $key => $spec) {
            if (in_array($norm, $spec['alias'], true)) {
                if (isset($map[$key])) {
                    throw new RuntimeException(
                        "Header tidak valid: kolom '{$spec['label']}' terdeteksi 2 kali " .
                        "(kolom " . ($usedBy[$key] + 1) . " dan " . ($idx + 1) . ")."
                    );
                }
                $map[$key] = $idx;
                $usedBy[$key] = $idx;
                break;
            }
        }
    }

    $missing = [];
    foreach ($expected as $key => $spec) {
        if (!isset($map[$key])) {
            $missing[] = $spec['label'];
        }
    }
    if ($missing) {
        throw new RuntimeException(
            'Header tidak valid: kolom berikut tidak ditemukan: ' . implode(', ', $missing) .
            '. Kolom wajib: ' . implode(' | ', array_column($expected, 'label')) . '.'
        );
    }
    return $map;
}

function bind_statement_params(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '' || !$params) {
        return;
    }

    $refs = [];
    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }
    array_unshift($refs, $types);
    $stmt->bind_param(...$refs);
}

function format_tanpa_pembulatan($n, $decimals = 2) {
    $factor = 10 ** $decimals;
    $truncated = $n >= 0 ? floor($n * $factor) / $factor : ceil($n * $factor) / $factor;
    $formatted = number_format($truncated, $decimals, ',', '.');
    return rtrim(rtrim($formatted, '0'), ',');
}

function rupiah($n) {
    return 'Rp ' . format_tanpa_pembulatan($n, 2);
}

function persen_tanpa_pembulatan($n, $decimals = 2) {
    return format_tanpa_pembulatan($n, $decimals) . '%';
}

