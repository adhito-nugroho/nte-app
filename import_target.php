<?php
require_once 'config.php';
$pageTitle = 'Import Target NTE';
$db = getDB();

$colTj = $db->query("SHOW COLUMNS FROM target_nte LIKE 'target_jumlah_produksi'");
if ($colTj && $colTj->num_rows === 0) {
    $db->query("ALTER TABLE target_nte ADD COLUMN target_jumlah_produksi DECIMAL(18,4) NULL DEFAULT NULL COMMENT 'Target volume/jumlah produksi' AFTER target_nte, ADD COLUMN satuan_produksi VARCHAR(50) NULL DEFAULT NULL COMMENT 'Satuan target produksi' AFTER target_jumlah_produksi");
}

$msg = '';
$msgType = '';
$errors = [];
$warnings = [];
$previewRows = [];
$summary = [
    'read' => 0,
    'success' => 0,
    'updated' => 0,
    'inserted' => 0,
    'skipped' => 0,
    'total_nte' => 0.0,
];

function normalize_import_text(string $value): string {
    $value = strtolower($value);
    $value = preg_replace('/\b(s\.?\s*p\.?|sp|s\.?\s*hut\.?|s\.?\s*h\.?|s\.?\s*m\.?|sst)\b/u', ' ', $value);
    $value = preg_replace('/\([^)]*\)/u', ' ', $value);
    $value = preg_replace('/[^a-z0-9]+/u', '', $value);
    return $value ?: '';
}

function import_similarity_score(string $needle, string $candidate): float {
    $a = normalize_import_text($needle);
    $b = normalize_import_text($candidate);
    if ($a === '' || $b === '') {
        return 0.0;
    }
    if ($a === $b) {
        return 1.0;
    }

    similar_text($a, $b, $pct);
    $score = $pct / 100;
    if (str_contains($a, $b) || str_contains($b, $a)) {
        $score = max($score, min(strlen($a), strlen($b)) / max(strlen($a), strlen($b)));
    }
    return $score;
}

function best_match(array $items, string $label, string $nameKey = 'nama'): ?array {
    $best = null;
    foreach ($items as $item) {
        $score = import_similarity_score($label, (string) $item[$nameKey]);
        if ($best === null || $score > $best['score']) {
            $best = ['item' => $item, 'score' => $score];
        }
    }
    return $best;
}

function split_komoditas_header(string $label): array {
    $unit = '';
    if (preg_match('/\(([^()]*)\)\s*$/u', $label, $m)) {
        $unit = trim($m[1]);
    }

    $name = preg_replace('/\([^)]*\)\s*$/u', '', $label);
    $name = preg_replace('/\s+/u', ' ', (string) $name);
    $name = trim($name, " \t\n\r\0\x0B-/");

    return [$name !== '' ? $name : trim($label), $unit];
}

function excel_target_number($value): ?float {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return (float) $value;
    }
    $parsed = parse_localized_number($value);
    return $parsed;
}

function fetch_penyuluh_options(mysqli $db): array {
    $rows = [];
    $res = $db->query("SELECT id, nama_penyuluh FROM penyuluh WHERE aktif=1 ORDER BY nama_penyuluh");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = ['id' => (int) $row['id'], 'nama' => $row['nama_penyuluh']];
        }
    }
    return $rows;
}

function fetch_kategori_options(mysqli $db): array {
    $rows = [];
    $res = $db->query("SELECT id, nama_kategori FROM kategori_produk ORDER BY nama_kategori");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = ['id' => (int) $row['id'], 'nama' => $row['nama_kategori']];
        }
    }
    return $rows;
}

function get_or_create_kategori(mysqli $db, string $nama): int {
    $stmt = $db->prepare("SELECT id FROM kategori_produk WHERE nama_kategori=? LIMIT 1");
    $stmt->bind_param('s', $nama);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return (int) $row['id'];
    }

    $stmt = $db->prepare("INSERT INTO kategori_produk (nama_kategori) VALUES (?)");
    $stmt->bind_param('s', $nama);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

function get_or_create_target_produk(mysqli $db, string $nama, int $kategoriId): int {
    $stmt = $db->prepare("SELECT id FROM jenis_produk WHERE nama_produk=? AND kategori_id=? LIMIT 1");
    $stmt->bind_param('si', $nama, $kategoriId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return (int) $row['id'];
    }

    $stmt = $db->prepare("INSERT INTO jenis_produk (nama_produk, kategori_id) VALUES (?, ?)");
    $stmt->bind_param('si', $nama, $kategoriId);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

function upsert_target_row(mysqli $db, int $penyuluhId, int $produkId, int $tahun, float $targetNte, ?float $targetJumlah, ?string $satuan, string $keterangan): string {
    $stmt = $db->prepare("SELECT id FROM target_nte WHERE penyuluh_id=? AND tahun=? AND jenis_produk_id=? LIMIT 1");
    $stmt->bind_param('iii', $penyuluhId, $tahun, $produkId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $id = (int) $row['id'];
        $stmt = $db->prepare("UPDATE target_nte SET target_nte=?, target_jumlah_produksi=?, satuan_produksi=?, keterangan=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param('ddssi', $targetNte, $targetJumlah, $satuan, $keterangan, $id);
        $stmt->execute();
        $stmt->close();
        return 'updated';
    }

    $stmt = $db->prepare("INSERT INTO target_nte (penyuluh_id, jenis_produk_id, tahun, target_nte, target_jumlah_produksi, satuan_produksi, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iiiddss', $penyuluhId, $produkId, $tahun, $targetNte, $targetJumlah, $satuan, $keterangan);
    $stmt->execute();
    $stmt->close();
    return 'inserted';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_target'])) {
    $tahun = (int) ($_POST['tahun'] ?? 0);
    $file = $_FILES['file_target'];
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $minPenyuluhScore = 0.55;
    $minKategoriScore = 0.50;

    if ($tahun < 2000 || $tahun > 2100) {
        $msg = 'Tahun target tidak valid.';
        $msgType = 'danger';
    } elseif (!in_array($ext, ['xlsx', 'xls'], true)) {
        $msg = 'Format file tidak valid. Upload file Excel .xlsx atau .xls.';
        $msgType = 'danger';
    } elseif (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $msg = 'Upload file gagal. Kode error: ' . (int) $file['error'];
        $msgType = 'danger';
    } elseif (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        $msg = 'PhpSpreadsheet tidak tersedia. Jalankan composer install terlebih dahulu.';
        $msgType = 'danger';
    } else {
        $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('target_nte_', true) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
            $msg = 'Gagal menyimpan file upload sementara.';
            $msgType = 'danger';
        } else {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, false);

                if (count($rows) < 3) {
                    throw new RuntimeException('Format file tidak dikenali. Minimal harus ada 2 baris header dan 1 baris data.');
                }

                $penyuluhOptions = fetch_penyuluh_options($db);
                $kategoriOptions = fetch_kategori_options($db);
                if (!$penyuluhOptions) {
                    throw new RuntimeException('Master penyuluh masih kosong.');
                }

                $pairs = [];
                $headerRow = $rows[1] ?? [];
                $maxCols = max(array_map('count', $rows));
                for ($col = 2; $col < $maxCols; $col += 2) {
                    $header = trim((string) ($headerRow[$col] ?? ''));
                    if ($header === '') {
                        continue;
                    }
                    [$komoditas, $satuan] = split_komoditas_header($header);
                    $pairs[] = [
                        'vol_col' => $col,
                        'rp_col' => $col + 1,
                        'raw' => $header,
                        'komoditas' => $komoditas,
                        'satuan' => $satuan,
                    ];
                }

                if (!$pairs) {
                    throw new RuntimeException('Header komoditas tidak ditemukan. Pastikan pasangan kolom dimulai dari kolom C.');
                }

                $db->begin_transaction();
                foreach (array_slice($rows, 2) as $rowIndex => $row) {
                    $excelRow = $rowIndex + 3;
                    $namaExcel = trim((string) ($row[1] ?? ''));
                    if ($namaExcel === '') {
                        continue;
                    }

                    $penyuluhMatch = best_match($penyuluhOptions, $namaExcel);
                    if (!$penyuluhMatch || $penyuluhMatch['score'] < $minPenyuluhScore) {
                        $summary['skipped']++;
                        $errors[] = "Baris $excelRow: penyuluh \"$namaExcel\" tidak punya kecocokan yang cukup kuat.";
                        continue;
                    }

                    $penyuluh = $penyuluhMatch['item'];
                    if ($penyuluhMatch['score'] < 0.80) {
                        $warnings[] = "Baris $excelRow: \"$namaExcel\" dicocokkan ke \"" . $penyuluh['nama'] . "\" (" . round($penyuluhMatch['score'] * 100) . "%).";
                    }

                    foreach ($pairs as $pair) {
                        $volume = excel_target_number($row[$pair['vol_col']] ?? null);
                        $rupiah = excel_target_number($row[$pair['rp_col']] ?? null);
                        if ($volume === null && $rupiah === null) {
                            continue;
                        }

                        $summary['read']++;
                        $targetNte = $rupiah !== null ? max(0.0, $rupiah) : 0.0;
                        $targetJumlah = $volume !== null && $volume > 0 ? $volume : null;
                        $satuan = $targetJumlah !== null && $pair['satuan'] !== '' ? $pair['satuan'] : null;
                        if ($targetNte <= 0 && ($targetJumlah === null || $targetJumlah <= 0)) {
                            $summary['skipped']++;
                            continue;
                        }

                        $kategoriMatch = best_match($kategoriOptions, $pair['komoditas']);
                        if ($kategoriMatch && $kategoriMatch['score'] >= $minKategoriScore) {
                            $kategoriId = (int) $kategoriMatch['item']['id'];
                            $kategoriName = $kategoriMatch['item']['nama'];
                            if ($kategoriMatch['score'] < 0.80) {
                                $warnings[] = "Kolom \"" . $pair['raw'] . "\" dicocokkan ke kategori \"$kategoriName\" (" . round($kategoriMatch['score'] * 100) . "%).";
                            }
                        } else {
                            $kategoriName = $pair['komoditas'];
                            $kategoriId = get_or_create_kategori($db, $kategoriName);
                            $kategoriOptions[] = ['id' => $kategoriId, 'nama' => $kategoriName];
                            $warnings[] = "Kategori baru dibuat dari kolom \"" . $pair['raw'] . "\": $kategoriName.";
                        }

                        $produkId = get_or_create_target_produk($db, $kategoriName, $kategoriId);
                        $keterangan = 'Import target Excel: ' . ($file['name'] ?? 'file target') . ' | Komoditas: ' . $pair['raw'];
                        $result = upsert_target_row($db, (int) $penyuluh['id'], $produkId, $tahun, $targetNte, $targetJumlah, $satuan, $keterangan);

                        $summary['success']++;
                        $summary[$result]++;
                        $summary['total_nte'] += $targetNte;
                        if (count($previewRows) < 25) {
                            $previewRows[] = [
                                'excel_row' => $excelRow,
                                'nama_excel' => $namaExcel,
                                'penyuluh' => $penyuluh['nama'],
                                'kategori' => $kategoriName,
                                'target_nte' => $targetNte,
                                'target_jumlah' => $targetJumlah,
                                'satuan' => $satuan,
                                'status' => $result,
                            ];
                        }
                    }
                }
                $db->commit();

                $msg = 'Import target selesai. ' . number_format($summary['success'], 0, ',', '.') . ' target berhasil diproses.';
                $msgType = $summary['success'] > 0 ? 'success' : 'warning';
            } catch (Throwable $e) {
                $db->rollback();
                $msg = 'Error: ' . $e->getMessage();
                $msgType = 'danger';
            } finally {
                if (is_file($tmpPath)) {
                    unlink($tmpPath);
                }
            }
        }
    }
}

$tahun_options = range((int) date('Y') - 2, (int) date('Y') + 2);
require_once 'header.php';
?>

<script>
window.tailwind = window.tailwind || {};
window.tailwind.config = { corePlugins: { preflight: false } };
</script>
<script src="https://cdn.tailwindcss.com"></script>

<div class="flex items-start justify-between gap-4">
  <div>
    <h1 class="text-xl font-semibold text-gray-900 mb-1">Import Target NTE</h1>
    <p class="text-sm text-gray-500 mb-0">Upload file target matrix: nama penyuluh di kolom B, komoditas mulai kolom C, pasangan volume dan Rp.</p>
  </div>
  <a href="target.php" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors whitespace-nowrap">
    <i class="bi bi-arrow-left"></i>Daftar Target
  </a>
</div>

<?php if ($msg): ?>
<?php
$alertTone = $msgType === 'success'
    ? ['wrap' => 'bg-green-50 border-green-200', 'text' => 'text-green-800', 'muted' => 'text-green-700', 'icon' => 'text-green-600', 'btn' => 'text-green-500 hover:text-green-700']
    : ($msgType === 'danger'
        ? ['wrap' => 'bg-red-50 border-red-200', 'text' => 'text-red-800', 'muted' => 'text-red-700', 'icon' => 'text-red-600', 'btn' => 'text-red-500 hover:text-red-700']
        : ['wrap' => 'bg-yellow-50 border-yellow-200', 'text' => 'text-yellow-800', 'muted' => 'text-yellow-700', 'icon' => 'text-yellow-600', 'btn' => 'text-yellow-500 hover:text-yellow-700']);
?>
<div class="flex items-center justify-between gap-3 px-4 py-3 <?=$alertTone['wrap']?> border rounded-lg mt-6 mb-6 text-sm">
  <div class="flex items-center gap-2 <?=$alertTone['text']?>">
    <svg class="w-4 h-4 <?=$alertTone['icon']?> shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" d="<?=$msgType === 'success' ? 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z' : 'M12 9v3.75m0 3.75h.008v.008H12V16.5Zm9-4.5a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'?>" />
    </svg>
    <span class="font-medium"><?=h(strip_tags($msg))?></span>
  </div>
  <button type="button" class="<?=$alertTone['btn']?> transition-colors" onclick="this.closest('div').remove()" aria-label="Tutup">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
    </svg>
  </button>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-5 gap-6 mt-6">
  <div class="lg:col-span-2">
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
      <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-200">
        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M7.5 7.5 12 3m0 0 4.5 4.5M12 3v13.5" />
        </svg>
        <h2 class="text-sm font-semibold text-gray-800 mb-0">Upload File Target</h2>
      </div>
      <div class="p-4">
        <form method="POST" enctype="multipart/form-data" data-disable-on-submit>
          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Tahun Target <span class="text-red-500">*</span></label>
            <select name="tahun" class="w-full px-3 py-2.5 text-sm border border-gray-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-emerald-700/20 focus:border-emerald-700" required>
              <?php foreach ($tahun_options as $y): ?>
              <option value="<?=$y?>" <?=$y == (int) date('Y') ? 'selected' : ''?>><?=$y?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1.5">File Excel <span class="text-red-500">*</span></label>
            <label for="file-upload" class="flex items-center gap-3 w-full px-3 py-2.5 border border-gray-300 rounded-lg cursor-pointer bg-white hover:bg-gray-50 transition-colors group">
              <span class="inline-flex items-center px-3 py-1 text-xs font-medium bg-gray-100 text-gray-700 rounded-md group-hover:bg-gray-200 transition-colors whitespace-nowrap">Pilih File</span>
              <span id="file-name-display" class="text-sm text-gray-400 truncate">Belum ada file dipilih</span>
            </label>
            <input id="file-upload" name="file_target" type="file" accept=".xlsx,.xls" class="sr-only" required
                   onchange="document.getElementById('file-name-display').textContent = this.files[0]?.name || 'Belum ada file dipilih'">
            <p class="mt-1.5 text-xs text-gray-500 mb-0">Import akan mencari nama penyuluh dan kategori terdekat, lalu menyimpan target per kategori.</p>
          </div>
          <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-3 bg-emerald-800 hover:bg-emerald-900 text-white text-sm font-semibold rounded-lg transition-colors shadow-sm active:scale-[0.98]">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-1M12 12V4m0 8-3-3m3 3 3-3" />
            </svg>
            Import Target
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="lg:col-span-3 flex flex-col gap-4">
    <div class="grid grid-cols-3 gap-3">
      <div class="bg-white rounded-xl border border-gray-200 px-4 py-4 shadow-sm min-w-0">
        <p class="text-3xl font-bold text-gray-900 leading-tight mb-0"><?=number_format($summary['success'], 0, ',', '.')?></p>
        <p class="text-xs text-gray-500 mt-1 mb-0">Target tersimpan</p>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 px-4 py-4 shadow-sm min-w-0">
        <p class="text-3xl font-bold text-gray-900 leading-tight mb-0"><?=number_format($summary['updated'], 0, ',', '.')?></p>
        <p class="text-xs text-gray-500 mt-1 mb-0">Diperbarui</p>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 px-4 py-4 shadow-sm min-w-0">
        <p class="text-lg font-bold text-emerald-700 leading-tight break-all mb-0"><?=rupiah($summary['total_nte'])?></p>
        <p class="text-xs text-gray-500 mt-1 mb-0">Total target Rp file</p>
      </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
      <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-200">
        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M9 6h6m-7.5 12h9A2.5 2.5 0 0 0 19 15.5v-9A2.5 2.5 0 0 0 16.5 4h-9A2.5 2.5 0 0 0 5 6.5v9A2.5 2.5 0 0 0 7.5 18Z" />
        </svg>
        <h2 class="text-sm font-semibold text-gray-800 mb-0">Hasil Import</h2>
      </div>
      <div>
        <?php if (!$previewRows): ?>
          <div class="empty-state">
            <i class="bi bi-file-earmark-arrow-up"></i>
            <div class="empty-state-title">Belum ada file diproses</div>
            <p class="empty-state-text">Pilih tahun dan upload file target untuk mulai mengisi target penyuluh.</p>
          </div>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="min-w-full">
              <thead>
                <tr class="border-b-2 border-gray-200">
                  <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide w-14">Baris</th>
                  <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Nama Excel</th>
                  <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Penyuluh Match</th>
                  <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Kategori</th>
                  <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide w-36">Target Rp</th>
                  <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide w-24">Volume</th>
                  <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide w-24">Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <?php foreach ($previewRows as $r): ?>
                <?php
                $statusClass = match ($r['status']) {
                    'updated' => 'bg-blue-100 text-blue-800',
                    'skipped' => 'bg-yellow-100 text-yellow-700',
                    'error' => 'bg-red-100 text-red-700',
                    default => 'bg-emerald-100 text-emerald-800',
                };
                ?>
                <tr class="hover:bg-gray-50 transition-colors">
                  <td class="px-3 py-3 text-sm text-gray-500 align-middle"><?=h($r['excel_row'])?></td>
                  <td class="px-3 py-3 text-sm text-gray-800 font-medium align-middle"><?=h($r['nama_excel'])?></td>
                  <td class="px-3 py-3 text-sm text-gray-700 align-middle"><?=h($r['penyuluh'])?></td>
                  <td class="px-3 py-3 align-middle">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 border border-gray-200 whitespace-nowrap"><?=h($r['kategori'])?></span>
                  </td>
                  <td class="px-3 py-3 text-sm text-gray-900 font-medium text-right align-middle whitespace-nowrap"><?=rupiah($r['target_nte'])?></td>
                  <td class="px-3 py-3 text-sm text-gray-600 text-right align-middle whitespace-nowrap">
                    <?= $r['target_jumlah'] !== null ? h(format_tanpa_pembulatan($r['target_jumlah'], 2) . ($r['satuan'] ? ' ' . $r['satuan'] : '')) : '<span class="text-gray-400">-</span>' ?>
                  </td>
                  <td class="px-3 py-3 text-center align-middle">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?=$statusClass?>"><?=h($r['status'])?></span>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($warnings): ?>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm mt-4">
  <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-200">
    <i class="bi bi-exclamation-triangle text-yellow-500"></i>
    <h2 class="text-sm font-semibold text-gray-800 mb-0">Catatan Matching</h2>
  </div>
  <div class="p-4 text-xs text-gray-600">
    <?php foreach (array_slice(array_unique($warnings), 0, 30) as $w): ?>
      <div class="mb-1">&bull; <?=h($w)?></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm mt-4">
  <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-200">
    <i class="bi bi-x-circle text-red-500"></i>
    <h2 class="text-sm font-semibold text-gray-800 mb-0">Data Dilewati</h2>
  </div>
  <div class="p-4 text-xs text-gray-600">
    <?php foreach (array_slice($errors, 0, 30) as $e): ?>
      <div class="mb-1">&bull; <?=h($e)?></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
