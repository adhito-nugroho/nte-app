<?php
require_once 'config.php';
$pageTitle = 'Import Data';
$db = getDB();

$colHash = $db->query("SHOW COLUMNS FROM import_log LIKE 'file_hash'");
if ($colHash && $colHash->num_rows === 0) {
    $db->query("ALTER TABLE import_log ADD COLUMN file_hash VARCHAR(64) NULL DEFAULT NULL");
    @$db->query("CREATE INDEX idx_import_log_file_hash ON import_log (file_hash)");
}
$colStoredName = $db->query("SHOW COLUMNS FROM import_log LIKE 'stored_filename'");
if ($colStoredName && $colStoredName->num_rows === 0) {
    $db->query("ALTER TABLE import_log ADD COLUMN stored_filename VARCHAR(255) NULL DEFAULT NULL");
}
$colFileSize = $db->query("SHOW COLUMNS FROM import_log LIKE 'file_size'");
if ($colFileSize && $colFileSize->num_rows === 0) {
    $db->query("ALTER TABLE import_log ADD COLUMN file_size BIGINT UNSIGNED NULL DEFAULT NULL");
}
$colMimeType = $db->query("SHOW COLUMNS FROM import_log LIKE 'mime_type'");
if ($colMimeType && $colMimeType->num_rows === 0) {
    $db->query("ALTER TABLE import_log ADD COLUMN mime_type VARCHAR(120) NULL DEFAULT NULL");
}

// Log keputusan matching penyuluh per import (additive: tidak menyentuh
// tabel inti). Ikuti pola defensif yang sama: buat jika belum ada.
$db->query("CREATE TABLE IF NOT EXISTS penyuluh_match_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    import_log_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'relasi ke import_log.id',
    raw_name VARCHAR(150) NOT NULL COMMENT 'nama persis seperti di file',
    matched_penyuluh_id INT NULL DEFAULT NULL COMMENT 'id master yang dipakai',
    matched_name VARCHAR(150) NULL DEFAULT NULL COMMENT 'nama master saat match',
    metode VARCHAR(20) NOT NULL DEFAULT 'exact' COMMENT 'exact|normalized|prefix|fuzzy|new',
    skor DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'skor kemiripan 0-100, NULL jika tdk relevan',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_match_log_import (import_log_id),
    INDEX idx_match_log_raw (raw_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

define('IMPORT_ARCHIVE_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'imports');
if (!is_dir(IMPORT_ARCHIVE_DIR)) {
    @mkdir(IMPORT_ARCHIVE_DIR, 0775, true);
}

$msg = ''; $msgType = ''; $errors = [];
// Mode "ganti": hanya data transaksi (data_nte) yang dihapus & diganti.
// Master (provinsi, kabupaten, penyuluh, kategori, produk), target_nte,
// dan riwayat import_log tetap dipertahankan.
$tablesToReplace = [
    'data_nte',
];

function importArchivePath(?string $storedFilename): ?string {
    if (!$storedFilename || basename($storedFilename) !== $storedFilename) {
        return null;
    }

    $path = IMPORT_ARCHIVE_DIR . DIRECTORY_SEPARATOR . $storedFilename;
    return is_file($path) ? $path : null;
}

function safeDownloadFilename(string $filename): string {
    $filename = trim(str_replace(["\r", "\n"], '', $filename));
    return $filename !== '' ? $filename : 'file-import.xlsx';
}

function createStoredImportFilename(string $originalName, string $fileHash, string $ext): string {
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $base = trim((string) $base, '._-');
    if ($base === '') {
        $base = 'import';
    }
    $base = substr($base, 0, 80);
    $hashPart = substr($fileHash, 0, 16);
    return date('Ymd_His') . '_' . $hashPart . '_' . $base . '.' . $ext;
}

function formatFileSize(?int $bytes): string {
    if (!$bytes || $bytes < 1) {
        return '-';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $size = (float) $bytes;
    $unitIndex = 0;
    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    return rtrim(rtrim(number_format($size, 1, ',', '.'), '0'), ',') . ' ' . $units[$unitIndex];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download_import'])) {
    $downloadId = (int) $_GET['download_import'];
    if ($downloadId < 1) {
        http_response_code(404);
        exit('File import tidak ditemukan.');
    }

    $stmt = $db->prepare('SELECT filename, stored_filename, file_size, mime_type FROM import_log WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $downloadId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $archivePath = $row ? importArchivePath($row['stored_filename'] ?? null) : null;
    if (!$row || !$archivePath) {
        http_response_code(404);
        exit('File import tidak tersedia untuk diunduh.');
    }

    $downloadName = safeDownloadFilename($row['filename'] ?? 'file-import.xlsx');
    $mimeType = $row['mime_type'] ?: 'application/octet-stream';
    $fileSize = is_numeric($row['file_size'] ?? null) ? (int) $row['file_size'] : filesize($archivePath);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $fileSize);
    header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, "\\\"") . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('X-Content-Type-Options: nosniff');
    readfile($archivePath);
    exit;
}

function parseExcelNumber($value) {
    // Single source of truth di config.php.
    return parse_localized_number($value);
}

function detectCsvDelimiter(string $path): string {
    $handle = @fopen($path, 'r');
    if (!$handle) {
        return ';';
    }
    $sample = '';
    for ($i = 0; $i < 3 && ($line = fgets($handle)) !== false; $i++) {
        $sample .= "\n" . $line;
    }
    fclose($handle);

    $candidates = [';' => 0, ',' => 0, "\t" => 0, '|' => 0];
    foreach ($candidates as $delim => &$count) {
        $count = substr_count($sample, $delim);
    }
    unset($count);

    $best = ';';
    foreach ($candidates as $delim => $count) {
        if ($count > $candidates[$best]) {
            $best = $delim;
        }
    }
    return $best;
}

function findImportHeaderIndex(array $rows): int {
    $limit = min(count($rows), 10);
    for ($i = 0; $i < $limit; $i++) {
        foreach ($rows[$i] as $cell) {
            if (is_string($cell) && stripos(trim($cell), 'penyuluh') !== false) {
                return $i;
            }
        }
    }
    return 0;
}

function getOrCreateProvinsi(mysqli $db, string $nama): int {
    $stmt = $db->prepare('SELECT id FROM provinsi WHERE nama_provinsi=? LIMIT 1');
    $stmt->bind_param('s', $nama);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        return (int) $row['id'];
    }

    $stmt = $db->prepare('INSERT INTO provinsi (nama_provinsi) VALUES (?)');
    $stmt->bind_param('s', $nama);
    $stmt->execute();
    return (int) $db->insert_id;
}

function getOrCreateKabupaten(mysqli $db, string $nama, int $provinsiId): int {
    $stmt = $db->prepare('SELECT id FROM kabupaten WHERE nama_kabupaten=? AND provinsi_id=? LIMIT 1');
    $stmt->bind_param('si', $nama, $provinsiId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        return (int) $row['id'];
    }

    $stmt = $db->prepare('INSERT INTO kabupaten (nama_kabupaten, provinsi_id) VALUES (?, ?)');
    $stmt->bind_param('si', $nama, $provinsiId);
    $stmt->execute();
    return (int) $db->insert_id;
}

function normalizeNamaPenyuluh(string $nama): string {
    $nama = strtolower($nama);
    $nama = preg_replace('/[^a-z0-9]+/', ' ', $nama);
    // Buang gelar & singkatan umum agar "WARSONO" == "Warsono, SP".
    $gelar = ['s', 'sp', 'hut', 'h', 'sst', 'sstt', 'sh', 'mm', 'mma', 'ma', 'msi', 'mhum', 'mp', 'se', 'st', 'si', 'dr', 'dra', 'ir', 'drs', 'prof'];
    $kata = array_filter(
        explode(' ', trim((string) $nama)),
        static function ($w) use ($gelar): bool {
            return $w !== '' && strlen($w) > 1 && !in_array($w, $gelar, true);
        }
    );
    return implode('', $kata);
}

function getOrCreatePenyuluh(mysqli $db, string $nama, int $kabupatenId): array {
    // 1. Cocok persis (kolasi DB umumnya case-insensitive).
    $stmt = $db->prepare('SELECT id, nama_penyuluh FROM penyuluh WHERE nama_penyuluh=? LIMIT 1');
    $stmt->bind_param('s', $nama);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        return ['id' => (int) $row['id'], 'status' => 'existing', 'matched' => $row['nama_penyuluh'], 'metode' => 'exact', 'skor' => 100.0];
    }

    // 2. Cocok ternormalisasi: "WARSONO" -> "Warsono, SP", beda kapital/gelar
    //    tidak lagi membuat duplikat di master. Aturan prefix menangani
    //    singkatan, aturan kemiripan menangani typo 1-2 huruf.
    $normFile = normalizeNamaPenyuluh($nama);
    if ($normFile !== '') {
        $all = $db->query('SELECT id, nama_penyuluh FROM penyuluh');
        if ($all) {
            $best = null;
            $bestScore = 0.0;
            $bestFuzzy = null;
            while ($r = $all->fetch_assoc()) {
                $normDb = normalizeNamaPenyuluh((string) $r['nama_penyuluh']);
                if ($normDb === '') {
                    continue;
                }
                if ($normDb === $normFile) {
                    $best = $r;
                    break;
                }
                $min = min(strlen($normDb), strlen($normFile));
                if ($min >= 10 && (str_starts_with($normDb, $normFile) || str_starts_with($normFile, $normDb))) {
                    similar_text($normFile, $normDb, $pctPrefix);
                    $best = $r;
                    $best['__skor'] = (float) $pctPrefix;
                    $best['__metode'] = 'prefix';
                    break;
                }
                similar_text($normFile, $normDb, $pct);
                if ($pct / 100 > $bestScore) {
                    $bestScore = $pct / 100;
                    $bestFuzzy = $r;
                }
            }
            if ($best) {
                if (($best['__metode'] ?? '') === 'prefix') {
                    return ['id' => (int) $best['id'], 'status' => 'normalized', 'matched' => $best['nama_penyuluh'], 'metode' => 'prefix', 'skor' => round((float) $best['__skor'], 2)];
                }
                return ['id' => (int) $best['id'], 'status' => 'normalized', 'matched' => $best['nama_penyuluh'], 'metode' => 'normalized', 'skor' => 100.0];
            }
            if ($bestScore >= 0.88 && $bestFuzzy !== null) {
                return ['id' => (int) $bestFuzzy['id'], 'status' => 'normalized', 'matched' => $bestFuzzy['nama_penyuluh'], 'metode' => 'fuzzy', 'skor' => round($bestScore * 100, 2)];
            }
        }
    }

    // 3. Benar-benar baru -> tambah ke master penyuluh.
    $stmt = $db->prepare('INSERT INTO penyuluh (nama_penyuluh, kabupaten_id) VALUES (?, ?)');
    $stmt->bind_param('si', $nama, $kabupatenId);
    $stmt->execute();
    return ['id' => (int) $db->insert_id, 'status' => 'new', 'matched' => $nama, 'metode' => 'new', 'skor' => null];
}

function getOrCreateKategoriProduk(mysqli $db, string $nama): ?int {
    if ($nama === '') {
        return null;
    }

    $stmt = $db->prepare('SELECT id FROM kategori_produk WHERE nama_kategori=? LIMIT 1');
    $stmt->bind_param('s', $nama);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        return (int) $row['id'];
    }

    $stmt = $db->prepare('INSERT INTO kategori_produk (nama_kategori) VALUES (?)');
    $stmt->bind_param('s', $nama);
    $stmt->execute();
    return (int) $db->insert_id;
}

function getOrCreateJenisProduk(mysqli $db, string $nama, ?int $kategoriId): ?int {
    if ($nama === '') {
        return null;
    }

    $stmt = $db->prepare('SELECT id FROM jenis_produk WHERE nama_produk=? LIMIT 1');
    $stmt->bind_param('s', $nama);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        return (int) $row['id'];
    }

    $stmt = $db->prepare('INSERT INTO jenis_produk (nama_produk, kategori_id) VALUES (?, ?)');
    $stmt->bind_param('si', $nama, $kategoriId);
    $stmt->execute();
    return (int) $db->insert_id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_FILES['file_excel']) || isset($_POST['action']))) {
    $action = $_POST['action'] ?? '';

    if ($action === 'check_import_filename') {
        header('Content-Type: application/json; charset=utf-8');
        $filename = trim($_POST['filename'] ?? '');
        if ($filename === '') {
            echo json_encode(['ok' => false, 'error' => 'Nama file tidak valid']);
            exit;
        }
        $fileHashIn = strtolower(preg_replace('/[^a-f0-9]/', '', (string) ($_POST['file_hash'] ?? '')));
        $hasValidHash = strlen($fileHashIn) === 64 && ctype_xdigit($fileHashIn);

        $mapRow = static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'filename' => $r['filename'],
                'imported_at' => $r['imported_at'],
                'total_rows' => (int) $r['total_rows'],
                'success_rows' => (int) $r['success_rows'],
                'failed_rows' => (int) $r['failed_rows'],
            ];
        };

        $nameMatch = null;
        $stmt = $db->prepare('SELECT id, filename, imported_at, total_rows, success_rows, failed_rows FROM import_log WHERE filename = ? ORDER BY imported_at DESC LIMIT 1');
        $stmt->bind_param('s', $filename);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $nameMatch = $mapRow($row);
        }
        $stmt->close();

        $contentMatch = null;
        if ($hasValidHash) {
            $stmt = $db->prepare('SELECT id, filename, imported_at, total_rows, success_rows, failed_rows FROM import_log WHERE file_hash = ? ORDER BY imported_at DESC LIMIT 1');
            $stmt->bind_param('s', $fileHashIn);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $contentMatch = $mapRow($row);
            }
            $stmt->close();
        }

        echo json_encode([
            'ok' => true,
            'content_check_done' => $hasValidHash,
            'name_match' => $nameMatch,
            'content_match' => $contentMatch,
            'previously_imported' => $nameMatch !== null || $contentMatch !== null,
        ]);
        exit;
    }

    if (isset($_FILES['file_excel'])) {
        $file = $_FILES['file_excel'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $importMode = ($_POST['import_mode'] ?? 'append') === 'replace' ? 'replace' : 'append';

        if (!in_array($ext, ['xlsx', 'xls', 'csv', 'html', 'htm'], true)) {
            $msg = 'Format file tidak valid. Harap upload file .xls, .xlsx, .csv, atau .html';
            $msgType = 'danger';
        } elseif (($file['size'] ?? 0) > 50 * 1024 * 1024) {
            $msg = 'Ukuran file terlalu besar. Maksimal 50 MB.';
            $msgType = 'danger';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $msg = 'Error upload file: ' . $file['error'];
            $msgType = 'danger';
        } else {
        $tmpPath = sys_get_temp_dir() . '/' . uniqid('nte_') . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
            $msg = 'Gagal menyimpan file upload sementara.';
            $msgType = 'danger';
            $tmpPath = null;
        } else {
        $fileHash = hash_file('sha256', $tmpPath);
        $fileSize = (int) filesize($tmpPath);
        $mimeType = function_exists('mime_content_type') ? (mime_content_type($tmpPath) ?: 'application/octet-stream') : 'application/octet-stream';

        $success = 0; $failed = 0; $errors = [];
        $penyuluhBaru = []; $penyuluhDikenali = [];
        $anomaliRows = []; $matchLog = [];

        if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            $savedArchivePath = null;
            try {
                // File .xls dari aplikasi seringkali HTML ala Excel (frameset,
                // tag khusus, entity aneh) -> Html Reader memicu warning
                // DOMDocument::loadHTML. Redam via libxml internal errors.
                $prevLibxml = libxml_use_internal_errors(true);
                try {
                    if ($ext === 'csv') {
                        // CSV lokal umumnya memakai pemisah titik-koma.
                        // Deteksi delimiter agar koma/tab juga didukung.
                        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
                        $reader->setDelimiter(detectCsvDelimiter($tmpPath));
                        $reader->setEnclosure('"');
                        $reader->setSheetIndex(0);
                        $spreadsheet = $reader->load($tmpPath);
                    } else {
                        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
                    }
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($prevLibxml);
                }
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, false);

                // Cari baris header (berisi kolom "Penyuluh") dalam 10 baris pertama.
                // File asli punya 1-2 baris judul di atas header, jadi offset
                // tidak bisa hardcode.
                $headerIndex = findImportHeaderIndex($rows);
                // Mapping berbasis NAMA header (urutan kolom bebas).
                // Gagal dengan pesan jelas bila ada kolom wajib yang hilang.
                $col = map_import_columns($rows[$headerIndex] ?? []);
                $dataRows = array_slice($rows, $headerIndex + 1);

                $db->begin_transaction();

                if ($importMode === 'replace') {
                    $db->query("SET FOREIGN_KEY_CHECKS=0");
                    foreach ($tablesToReplace as $tbl) {
                        $db->query("TRUNCATE TABLE $tbl");
                    }
                    $db->query("SET FOREIGN_KEY_CHECKS=1");
                }

                // Snapshot rata-rata harga satuan historis per (produk, kab)
                // SEBELUM insert, agar baris file ini tidak mencemari pembanding.
                $hargaRata = [];
                $stmtAvg = $db->prepare('SELECT jenis_produk_id, kabupaten_id, AVG(nte_kth / jumlah_penjualan) AS rata FROM data_nte WHERE jumlah_penjualan > 0 GROUP BY jenis_produk_id, kabupaten_id');
                $stmtAvg->execute();
                $resAvg = $stmtAvg->get_result();
                while ($rAvg = $resAvg->fetch_assoc()) {
                    $hargaRata[$rAvg['jenis_produk_id'] . ':' . $rAvg['kabupaten_id']] = (float) $rAvg['rata'];
                }
                $stmtAvg->close();

                $tahunMin = 2020;
                $tahunMax = (int) date('Y') + 1;

                foreach ($dataRows as $rowNum => $row) {
                    $excelRow = $headerIndex + 2 + $rowNum; // nomor baris aktual di file (1-based)

                    // Baris kosong total -> lewati diam-diam (tidak dihitung).
                    $semuaKosong = true;
                    foreach ($row as $c) {
                        if (trim((string) $c) !== '') {
                            $semuaKosong = false;
                            break;
                        }
                    }
                    if ($semuaKosong) {
                        continue;
                    }

                    $provinsi = trim((string) ($row[$col['provinsi']] ?? ''));
                    $kabupaten = trim((string) ($row[$col['kabupaten']] ?? ''));
                    $nama_kelompok = trim((string) ($row[$col['nama_kelompok']] ?? ''));
                    $tahunRaw = trim((string) ($row[$col['tahun']] ?? ''));
                    $bulanRaw = trim((string) ($row[$col['bulan']] ?? ''));
                    $barang_jasa = trim((string) ($row[$col['barang_jasa']] ?? ''));
                    $jenis_produk = trim((string) ($row[$col['jenis_produk']] ?? ''));
                    $jumlah = parseExcelNumber($row[$col['jumlah']] ?? 0);
                    $satuan = trim((string) ($row[$col['satuan']] ?? ''));
                    $nte_kth = parseExcelNumber($row[$col['nte']] ?? 0);
                    $penyuluh = trim((string) ($row[$col['penyuluh']] ?? ''));

                    // Validasi baris dengan alasan spesifik.
                    $alasan = validate_import_row($provinsi, $kabupaten, $penyuluh, $tahunRaw, $bulanRaw, $jumlah, $nte_kth, $tahunMin, $tahunMax);
                    if ($alasan !== null) {
                        $failed++;
                        $errors[] = "Baris $excelRow: $alasan.";
                        continue;
                    }
                    $tahun = (int) $tahunRaw;
                    $bulan = (int) $bulanRaw;

                    $prov_id = getOrCreateProvinsi($db, $provinsi);
                    $kab_id = getOrCreateKabupaten($db, $kabupaten, $prov_id);
                    // Sinkron ke master penyuluh: baru ditambah, beda penulisan dikenali.
                    $penyuluhSync = getOrCreatePenyuluh($db, $penyuluh, $kab_id);
                    $penyuluh_id = $penyuluhSync['id'];
                    if ($penyuluhSync['status'] === 'new') {
                        $penyuluhBaru[$penyuluh] = true;
                    } elseif ($penyuluhSync['status'] === 'normalized') {
                        $penyuluhDikenali[$penyuluh] = $penyuluhSync['matched'];
                    }
                    // Catat keputusan match per nama unik untuk audit (tabel log).
                    if (!isset($matchLog[$penyuluh])) {
                        $matchLog[$penyuluh] = [
                            'matched_id' => $penyuluhSync['id'],
                            'matched_name' => $penyuluhSync['matched'],
                            'metode' => $penyuluhSync['metode'],
                            'skor' => $penyuluhSync['skor'],
                        ];
                    }
                    $kat_id = getOrCreateKategoriProduk($db, $barang_jasa);
                    $produk_id = getOrCreateJenisProduk($db, $jenis_produk, $kat_id);

                    // Insert data NTE (semua baris sesuai file excel).
                    $stmt = $db->prepare("INSERT INTO data_nte (penyuluh_id, kabupaten_id, kategori_id, jenis_produk_id, nama_kelompok, tahun, bulan, jumlah_penjualan, satuan, nte_kth)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('iiiisiidsd', $penyuluh_id, $kab_id, $kat_id, $produk_id, $nama_kelompok, $tahun, $bulan, $jumlah, $satuan, $nte_kth);
                    if ($stmt->execute()) {
                        $success++;
                        // Deteksi anomali harga satuan vs historis (produk, kab).
                        // Tetap diimport, hanya ditandai untuk review manual.
                        if ($jumlah > 0 && $produk_id && $kab_id) {
                            $rata = $hargaRata[$produk_id . ':' . $kab_id] ?? null;
                            if ($rata !== null) {
                                $deviasi = deviasi_harga($nte_kth / $jumlah, $rata);
                                if ($deviasi !== null) {
                                    $anomaliRows[] = [
                                        'baris' => $excelRow,
                                        'kelompok' => $nama_kelompok,
                                        'kabupaten' => $kabupaten,
                                        'produk' => $jenis_produk !== '' ? $jenis_produk : '-',
                                        'penyuluh' => $penyuluh,
                                        'jumlah' => $jumlah,
                                        'satuan' => $satuan,
                                        'nte' => $nte_kth,
                                        'harga' => $nte_kth / $jumlah,
                                        'rata' => $rata,
                                        'deviasi' => $deviasi,
                                    ];
                                }
                            }
                        }
                    } else { $failed++; $errors[] = "Baris $excelRow: " . $stmt->error; }
                }

                // Arsip file asli agar bisa diunduh ulang dari riwayat import.
                $totalRows = $success + $failed;
                $logName = $file['name'];
                $storedFilename = createStoredImportFilename($logName, $fileHash, $ext);
                $archivePath = IMPORT_ARCHIVE_DIR . DIRECTORY_SEPARATOR . $storedFilename;
                if (!is_dir(IMPORT_ARCHIVE_DIR) || !copy($tmpPath, $archivePath)) {
                    throw new RuntimeException('Data berhasil diproses, tetapi file import gagal disimpan ke arsip.');
                }
                $savedArchivePath = $archivePath;

                // Log import (nama + hash isi file SHA-256 + arsip file asli)
                $stmtLog = $db->prepare('INSERT INTO import_log (filename, file_hash, stored_filename, file_size, mime_type, total_rows, success_rows, failed_rows) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmtLog->bind_param('sssisiii', $logName, $fileHash, $storedFilename, $fileSize, $mimeType, $totalRows, $success, $failed);
                $stmtLog->execute();
                $importLogId = (int) $db->insert_id;
                $stmtLog->close();

                // Audit keputusan match penyuluh (satu baris per nama unik di file).
                if ($matchLog) {
                    $stmtMatch = $db->prepare('INSERT INTO penyuluh_match_log (import_log_id, raw_name, matched_penyuluh_id, matched_name, metode, skor) VALUES (?, ?, ?, ?, ?, ?)');
                    $mRaw = '';
                    $mId = 0;
                    $mName = '';
                    $mMet = '';
                    $mSkor = null;
                    $stmtMatch->bind_param('isssss', $importLogId, $mRaw, $mId, $mName, $mMet, $mSkor);
                    foreach ($matchLog as $rawName => $m) {
                        $mRaw = (string) $rawName;
                        $mId = (int) $m['matched_id'];
                        $mName = (string) $m['matched_name'];
                        $mMet = (string) $m['metode'];
                        $mSkor = $m['skor'] === null ? null : number_format((float) $m['skor'], 2, '.', '');
                        $stmtMatch->execute();
                    }
                    $stmtMatch->close();
                }

                $db->commit();

                $modeLabel = $importMode === 'replace' ? 'Mode ganti data transaksi' : 'Mode tambah ke data yang ada';
                $msg = "$modeLabel selesai. <strong>$success baris</strong> berhasil diimport" . ($failed>0 ? ", $failed baris gagal." : ".");
                $nBaru = count($penyuluhBaru);
                $nKenal = count($penyuluhDikenali);
                $msg .= "<br>Master penyuluh: <strong>$nBaru nama baru</strong> ditambahkan"
                    . ($nKenal > 0 ? ", $nKenal nama di file dikenali ke master yang ada" : "")
                    . ".";
                $nAnomali = count($anomaliRows);
                if ($nAnomali > 0) {
                    $msg .= "<br><strong>$nAnomali baris</strong> ditandai anomali harga satuan (tetap diimport — perlu dicek manual, lihat rincian di bawah).";
                }
                $nNonExact = 0;
                foreach ($matchLog as $m) {
                    if (in_array($m['metode'], ['normalized', 'prefix', 'fuzzy'], true)) {
                        $nNonExact++;
                    }
                }
                if ($nNonExact > 0) {
                    $msg .= "<br><strong>$nNonExact nama</strong> dicocokkan tidak persis (lihat rincian di bawah untuk spot-check).";
                }
                $msgType = ($failed > 0 || $nAnomali > 0) ? 'warning' : 'success';
            } catch (Exception $e) {
                $db->rollback();
                $db->query("SET FOREIGN_KEY_CHECKS=1");
                if ($savedArchivePath && is_file($savedArchivePath)) {
                    unlink($savedArchivePath);
                }
                $msg = 'Error: ' . $e->getMessage();
                $msgType = 'danger';
            }
        } else {
            $msg = '<strong>PhpSpreadsheet tidak terinstall.</strong> Jalankan: <code>composer require phpoffice/phpspreadsheet</code> di folder aplikasi.';
            $msgType = 'warning';
        }

            if ($tmpPath && is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }
        }
    }
}

// Summary + history import
$total_records = (int) $db->query("SELECT COUNT(*) AS c FROM data_nte")->fetch_assoc()['c'];
$jumlah_import = (int) $db->query("SELECT COUNT(*) AS c FROM import_log")->fetch_assoc()['c'];
$import_terakhir = $db->query("SELECT MAX(imported_at) AS last_import FROM import_log")->fetch_assoc()['last_import'] ?? null;
$import_history = $db->query("SELECT * FROM import_log ORDER BY imported_at DESC LIMIT 20");

require_once 'header.php';
?>

<div class="import-page-wrap">
  <div class="row g-3 mb-4 import-summary-row">
    <div class="col-md-4">
      <div class="import-summary-card">
        <div class="import-summary-label">Total Record Saat Ini</div>
        <div class="import-summary-value text-success"><?=number_format($total_records, 0, ',', '.')?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="import-summary-card">
        <div class="import-summary-label">Jumlah Import</div>
        <div class="import-summary-value text-primary"><?=number_format($jumlah_import, 0, ',', '.')?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="import-summary-card">
        <div class="import-summary-label">Import Terakhir</div>
        <div class="import-summary-value import-summary-date">
          <?=$import_terakhir ? h(date('d M Y', strtotime($import_terakhir))) : '-'?>
        </div>
      </div>
    </div>
  </div>

<?php if ($msg): ?>
<div class="alert alert-<?=$msgType?> alert-dismissible fade show" role="alert">
  <i class="bi bi-<?=$msgType=='success'?'check-circle-fill':($msgType=='danger'?'x-circle-fill':'exclamation-triangle-fill')?> me-2"></i>
  <?=$msg?>
  <?php if (!empty($errors)): ?>
    <div class="mt-2" style="font-size:12px">
      <?php foreach(array_slice($errors, 0, 5) as $e): ?>
        <div>&bull; <?=htmlspecialchars($e)?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php
$showAnomali = $anomaliRows ?? [];
$showMatch = [];
foreach (($matchLog ?? []) as $rawName => $m) {
    if (in_array($m['metode'], ['normalized', 'prefix', 'fuzzy'], true)) {
        $showMatch[] = ['raw' => $rawName] + $m;
    }
}
?>
<?php if ($showAnomali): ?>
  <div class="card mb-4" style="border-color:#fbd38d;">
    <div class="card-header d-flex align-items-center gap-2" style="background:#fffaf0;color:#92400e;">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span class="fw-semibold" style="font-size:13.5px;">Anomali Harga Satuan — <?=count($showAnomali)?> baris perlu review</span>
    </div>
    <div class="card-body p-3" style="font-size:12px;">
      <p class="text-muted mb-2" style="font-size:12px;">Harga satuan (NTE ÷ jumlah) menyimpang &gt;25% dari rata-rata historis kombinasi produk + kabupaten yang sama. Data <strong>tetap diimport</strong> — cek manual ke sumber asli.</p>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0" style="font-size:12.5px;">
        <thead>
          <tr>
            <th style="width:60px;">Baris</th>
            <th>Kelompok</th>
            <th>Produk</th>
            <th>Penyuluh</th>
            <th style="text-align:right;">Harga Satuan</th>
            <th style="text-align:right;">Rata-rata Historis</th>
            <th style="text-align:center;">Deviasi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (array_slice($showAnomali, 0, 30) as $a): ?>
          <tr>
            <td class="text-muted tabular-nums"><?=(int) $a['baris']?></td>
            <td><?=h($a['kelompok'] ?: '-')?> <span class="badge bg-light text-dark border"><?=h($a['kabupaten'])?></span></td>
            <td><?=h($a['produk'])?></td>
            <td style="font-size:12px;"><?=h($a['penyuluh'])?></td>
            <td style="text-align:right;" class="tabular-nums fw-semibold"><?=rupiah((float) $a['harga'])?> <span class="text-muted" style="font-weight:400;">/ <?=h(trim(format_tanpa_pembulatan((float) $a['jumlah'], 2) . ' ' . $a['satuan']))?></span></td>
            <td style="text-align:right;" class="tabular-nums text-muted"><?=rupiah((float) $a['rata'])?></td>
            <td style="text-align:center;"><span class="badge bg-warning text-dark tabular-nums">+<?=format_tanpa_pembulatan((float) $a['deviasi'], 1)?>%</span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($showAnomali) > 30): ?>
    <div class="card-footer"><small class="text-muted">Menampilkan 30 dari <?=count($showAnomali)?> baris.</small></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($showMatch): ?>
  <div class="card mb-4" style="border-color:#bee3f8;">
    <div class="card-header d-flex align-items-center gap-2" style="background:#ebf8ff;color:#2b6cb0;">
      <i class="bi bi-person-check-fill"></i>
      <span class="fw-semibold" style="font-size:13.5px;">Match Penyuluh Tidak Persis — <?=count($showMatch)?> nama (spot-check)</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0" style="font-size:12.5px;">
        <thead>
          <tr>
            <th>Nama di File</th>
            <th>Diassign ke Master</th>
            <th style="text-align:center;width:110px;">Metode</th>
            <th style="text-align:right;width:90px;">Skor</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($showMatch as $m): ?>
          <tr>
            <td class="fw-semibold"><?=h($m['raw'])?></td>
            <td><?=h($m['matched_name'])?></td>
            <td style="text-align:center;"><span class="badge bg-light text-dark border"><?=h($m['metode'])?></span></td>
            <td style="text-align:right;" class="tabular-nums"><?= $m['skor'] === null ? '&mdash;' : format_tanpa_pembulatan((float) $m['skor'], 1) . '%' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

  <div class="card import-main-card mb-4">
    <div class="card-body">
      <h5 class="import-section-title"><i class="bi bi-box-arrow-in-down text-primary me-2"></i>Import File Excel / CSV / HTML</h5>

      <div class="import-format-note">
        <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>
        <strong>Format File yang Didukung:</strong> .xlsx (Excel), .xls (HTML format dari aplikasi), .csv (pemisah titik-koma/koma/tab), atau .html dengan tabel data NTE.
        <br><strong>Kolom yang dibutuhkan:</strong> No, Provinsi, Kab/Kota, Nama Kelompok, Tahun, Bulan, Barang/Jasa, Jenis Produk, Jumlah Penjualan, Satuan, NTE KTH, Penyuluh.
        <br><strong>Urutan kolom boleh berbeda</strong> — yang penting nama header sesuai. Baris dengan tahun/bulan tidak valid atau angka negatif ditolak dengan alasan per baris. Nama penyuluh yang beda kapital/gelar/typo kecil otomatis dicocokkan ke master, tidak membuat duplikat baru.
      </div>

      <form method="POST" enctype="multipart/form-data" id="importForm">
        <label class="import-field-label">Pilih File</label>
        <div class="upload-area import-upload-area mb-3" id="uploadArea">
          <i class="bi bi-folder-fill import-upload-icon"></i>
          <div class="mt-3 import-upload-title">Klik untuk memilih file atau drag & drop di sini</div>
          <div class="import-upload-subtitle">.xlsx, .xls, .csv, .html &bull; Maks 50 MB</div>
        </div>
        <input type="file" name="file_excel" id="fileInput" accept=".xlsx,.xls,.csv,.html,.htm" class="d-none">
        <div id="filePreview" class="d-none mb-3 import-file-preview">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-file-earmark-spreadsheet-fill text-success fs-4"></i>
            <div>
              <div id="fileName" class="import-file-name"></div>
              <div id="fileSize" class="import-file-size"></div>
            </div>
            <button type="button" onclick="clearFile()" class="btn btn-sm btn-link text-danger ms-auto p-0" aria-label="Hapus file"><i class="bi bi-x-lg"></i></button>
          </div>
        </div>

        <label class="import-field-label">Mode Import</label>
        <div class="import-mode-row mb-3" role="radiogroup" aria-label="Mode import">
          <label class="import-mode-option active">
            <input type="radio" name="import_mode" value="append" checked>
            <span class="mode-dot"></span>
            <i class="bi bi-plus-lg text-primary"></i>
            <span>Tambah ke data yang ada</span>
          </label>
          <label class="import-mode-option">
            <input type="radio" name="import_mode" value="replace">
            <span class="mode-dot"></span>
            <i class="bi bi-arrow-repeat text-danger"></i>
            <span>Ganti semua data transaksi (master &amp; target tetap)</span>
          </label>
        </div>

        <div class="d-flex flex-wrap gap-2">
          <button type="submit" class="btn btn-primary import-submit-btn" id="submitBtn">
            <i class="bi bi-box-arrow-in-down me-2"></i>Mulai Import
          </button>
          <a href="index.php" class="btn btn-light border import-back-btn"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
        </div>
      </form>
    </div>
  </div>

    <!-- Konfirmasi sebelum import -->
    <div class="modal fade" id="importConfirmModal" tabindex="-1" aria-labelledby="importConfirmModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title fw-600" id="importConfirmModalLabel"><i class="bi bi-shield-check me-2 text-primary"></i>Konfirmasi import</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
          </div>
          <div class="modal-body pt-2">
            <p class="mb-2" id="importConfirmMessage" style="font-size:14px;line-height:1.55"></p>
            <p class="mb-0 text-muted" style="font-size:12.5px" id="importConfirmHint"></p>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="importConfirmCancel">Batalkan</button>
            <button type="button" class="btn btn-primary" id="importConfirmProceed"><i class="bi bi-upload me-1"></i>Lanjutkan import</button>
          </div>
        </div>
      </div>
    </div>

  <div class="card import-history-card">
    <div class="card-body">
      <h5 class="import-section-title"><i class="bi bi-clipboard-data text-warning me-2"></i>Riwayat Import</h5>
      <?php if ($import_history->num_rows == 0): ?>
        <div class="empty-state">
          <i class="bi bi-file-earmark-arrow-up"></i>
          <div class="empty-state-title">Belum ada riwayat import</div>
          <p class="empty-state-text">Upload file pertama untuk mulai mengisi data transaksi, master penyuluh, kategori, dan produk.</p>
        </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table import-history-table mb-0">
          <thead>
            <tr>
              <th>Waktu</th>
              <th>Nama File</th>
              <th>Total</th>
              <th>Berhasil</th>
              <th>Gagal</th>
              <th>Status</th>
              <th>File</th>
            </tr>
          </thead>
          <tbody>
            <?php while($h=$import_history->fetch_assoc()):
              $failedRows = (int) $h['failed_rows'];
              $statusText = $failedRows > 0 ? 'Sebagian' : 'Sukses';
              $statusClass = $failedRows > 0 ? 'import-status-partial' : 'import-status-success';
              $archivePath = importArchivePath($h['stored_filename'] ?? null);
              $fileAvailable = $archivePath !== null;
              $fileSizeText = formatFileSize(isset($h['file_size']) ? (int) $h['file_size'] : null);
            ?>
            <tr>
              <td><?=h(date('d/m/Y H:i', strtotime($h['imported_at'])))?></td>
              <td><span class="import-history-file" title="<?=h($h['filename'])?>"><?=h($h['filename'])?></span></td>
              <td><?=number_format((int) $h['total_rows'], 0, ',', '.')?></td>
              <td class="text-success"><?=number_format((int) $h['success_rows'], 0, ',', '.')?></td>
              <td class="<?=$failedRows > 0 ? 'text-danger' : 'text-success'?>"><?=number_format($failedRows, 0, ',', '.')?></td>
              <td><span class="import-status <?=$statusClass?>"><?=$failedRows > 0 ? '!' : 'OK'?> <?=h($statusText)?></span></td>
              <td>
                <?php if ($fileAvailable): ?>
                  <a class="btn btn-sm btn-outline-primary import-download-btn" href="import.php?download_import=<?=(int) $h['id']?>" title="Download ulang <?=h($h['filename'])?>">
                    <i class="bi bi-download"></i><span>Download</span>
                  </a>
                  <div class="import-file-size mt-1"><?=h($fileSizeText)?></div>
                <?php else: ?>
                  <span class="import-file-missing" title="File lama belum tersimpan di arsip">Tidak tersedia</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$extraJS = <<<JS
<script>
const uploadArea = document.getElementById('uploadArea');
const fileInput = document.getElementById('fileInput');
const modeOptions = document.querySelectorAll('.import-mode-option');

uploadArea.addEventListener('click', () => fileInput.click());
uploadArea.addEventListener('dragover', e => { e.preventDefault(); uploadArea.classList.add('dragover'); });
uploadArea.addEventListener('dragleave', () => { uploadArea.classList.remove('dragover'); });
uploadArea.addEventListener('drop', e => {
  e.preventDefault();
  uploadArea.classList.remove('dragover');
  if(e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; showPreview(e.dataTransfer.files[0]); }
});
fileInput.addEventListener('change', function() { if(this.files.length) showPreview(this.files[0]); });
modeOptions.forEach(option => {
  const input = option.querySelector('input[type="radio"]');
  option.addEventListener('click', function() {
    modeOptions.forEach(item => item.classList.remove('active'));
    option.classList.add('active');
    input.checked = true;
  });
});

function showPreview(file) {
  document.getElementById('fileName').textContent = file.name;
  document.getElementById('fileSize').textContent = (file.size/1024).toFixed(1)+' KB';
  document.getElementById('filePreview').classList.remove('d-none');
  document.getElementById('uploadArea').classList.add('d-none');
}
function clearFile() {
  fileInput.value='';
  document.getElementById('filePreview').classList.add('d-none');
  document.getElementById('uploadArea').classList.remove('d-none');
}

let importConfirmed = false;
const importForm = document.getElementById('importForm');
const importConfirmModalEl = document.getElementById('importConfirmModal');
const importConfirmModal = importConfirmModalEl ? new bootstrap.Modal(importConfirmModalEl) : null;

function formatImportDate(isoStr) {
  if (!isoStr) return '';
  const d = new Date(isoStr.replace(' ', 'T'));
  if (isNaN(d.getTime())) return isoStr;
  return d.toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
}

function escapeHtml(s) {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

function rowInfoHtml(L) {
  return 'Terakhir: <strong>' + formatImportDate(L.imported_at) + '</strong> &mdash; Total baris: ' + L.total_rows +
    ', berhasil: ' + L.success_rows + (L.failed_rows > 0 ? ', gagal: ' + L.failed_rows : '') + '.';
}

async function sha256HexOfFile(file) {
  if (!window.crypto || !window.crypto.subtle) {
    return '';
  }
  const buf = await file.arrayBuffer();
  const hashBuf = await crypto.subtle.digest('SHA-256', buf);
  const arr = new Uint8Array(hashBuf);
  let hex = '';
  for (let i = 0; i < arr.length; i++) {
    hex += arr[i].toString(16).padStart(2, '0');
  }
  return hex;
}

const submitBtnDefaultHtml = '<i class="bi bi-box-arrow-in-down me-2"></i>Mulai Import';

importForm.addEventListener('submit', async function(e) {
  if (importConfirmed) {
    const btn = document.getElementById('submitBtn');
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memproses...';
    btn.disabled = true;
    return;
  }
  e.preventDefault();
  const file = fileInput.files[0];
  if (!file) {
    alert('Pilih file terlebih dahulu.');
    return;
  }
  const importMode = importForm.querySelector('input[name="import_mode"]:checked')?.value || 'append';
  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memeriksa isi file...';
  let fileHash = '';
  try {
    fileHash = await sha256HexOfFile(file);
  } catch (err) {
    fileHash = '';
  }
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Membandingkan riwayat...';
  const fd = new FormData();
  fd.append('action', 'check_import_filename');
  fd.append('filename', file.name);
  fd.append('file_hash', fileHash);
  let data;
  try {
    const res = await fetch('import.php', { method: 'POST', body: fd });
    const raw = (await res.text()).replace(/^\uFEFF+/, '').trim();
    data = JSON.parse(raw);
  } catch (err) {
    alert('Gagal memeriksa riwayat import. Periksa koneksi lalu coba lagi.');
    btn.disabled = false;
    btn.innerHTML = submitBtnDefaultHtml;
    return;
  }
  if (!data.ok) {
    alert(data.error || 'Tidak dapat memeriksa file.');
    btn.disabled = false;
    btn.innerHTML = submitBtnDefaultHtml;
    return;
  }
  btn.disabled = false;
  btn.innerHTML = submitBtnDefaultHtml;
  const msgEl = document.getElementById('importConfirmMessage');
  const hintEl = document.getElementById('importConfirmHint');
  const nm = data.name_match;
  const cm = data.content_match;
  const modeWarning = importMode === 'replace'
    ? '<div class="alert alert-warning mt-2 mb-0" style="font-size:12.5px"><strong>Mode ganti data transaksi aktif.</strong> Seluruh data transaksi lama akan dihapus lalu diganti dengan isi file ini. Master penyuluh, target, dan riwayat import tetap dipertahankan.</div>'
    : '';

  if (!nm && !cm) {
    msgEl.innerHTML = 'Berdasarkan nama file dan sidik digital isi (SHA-256), file ini belum tercatat di riwayat import.' + modeWarning;
    if (!data.content_check_done) {
      hintEl.innerHTML = '<span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Pemeriksaan isi tidak berjalan (misalnya browser membutuhkan HTTPS/localhost). Yang dibandingkan hanya <strong>nama file</strong>.</span>';
    } else {
      hintEl.textContent = 'Anda dapat melanjutkan import atau membatalkan.';
    }
    hintEl.classList.remove('d-none');
  } else if (nm && cm && nm.id === cm.id) {
    msgEl.innerHTML = '<strong>File ini sudah pernah diimpor</strong> &mdash; nama dan isi sama dengan satu entri di riwayat.' + modeWarning;
    hintEl.innerHTML = rowInfoHtml(nm);
    hintEl.classList.remove('d-none');
  } else {
    const bullets = [];
    if (nm) {
      bullets.push('<strong>Nama file pernah dipakai:</strong> ' + rowInfoHtml(nm));
    }
    if (cm) {
      const sameName = cm.filename === file.name;
      const ref = sameName ? 'nama file ini' : 'file <span class="text-break fw-600">' + escapeHtml(cm.filename) + '</span>';
      bullets.push('<strong>Isi file identik</strong> (sidik digital sama) dengan import sebelumnya &mdash; ' + ref + '. ' + rowInfoHtml(cm));
    }
    if (!data.content_check_done && !cm) {
      bullets.push('<span class="text-warning">Sidik digital isi tidak dihitung; entri riwayat lama mungkin belum punya hash isi.</span>');
    }
    msgEl.innerHTML = 'Hasil pemeriksaan sebelum import:' + modeWarning;
    hintEl.innerHTML = '<ul class="mb-0 ps-3" style="font-size:13px;line-height:1.55">' + bullets.map(function(b) { return '<li class="mb-2">' + b + '</li>'; }).join('') + '</ul>';
    hintEl.classList.remove('d-none');
  }
  const oldProceed = document.getElementById('importConfirmProceed');
  const proceedBtn = oldProceed.cloneNode(true);
  oldProceed.parentNode.replaceChild(proceedBtn, oldProceed);
  proceedBtn.addEventListener('click', function() {
    importConfirmed = true;
    importConfirmModal.hide();
    document.getElementById('submitBtn').innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memproses...';
    document.getElementById('submitBtn').disabled = true;
    importForm.requestSubmit();
  }, { once: true });
  importConfirmModal.show();
});

importConfirmModalEl.addEventListener('hidden.bs.modal', function() {
  if (!importConfirmed) {
    const b = document.getElementById('submitBtn');
    b.disabled = false;
    b.innerHTML = submitBtnDefaultHtml;
  }
});

</script>
JS;
require_once 'footer.php';
?>
