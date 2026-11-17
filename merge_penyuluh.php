<?php
require_once 'config.php';
$pageTitle = 'Gabung Duplikat Penyuluh';
$db = getDB();

$msg = ''; $msgType = '';

// ── Normalisasi nama (sama persis dengan import.php) ─────────────
function merge_norm_nama(string $nama): string {
    $nama = strtolower($nama);
    $nama = preg_replace('/[^a-z0-9]+/', ' ', $nama);
    $gelar = ['s', 'sp', 'hut', 'h', 'sst', 'sstt', 'sh', 'mm', 'mma', 'ma', 'msi', 'mhum', 'mp', 'se', 'st', 'si', 'dr', 'dra', 'ir', 'drs', 'prof'];
    $kata = array_filter(
        explode(' ', trim((string) $nama)),
        static function ($w) use ($gelar): bool {
            return $w !== '' && strlen($w) > 1 && !in_array($w, $gelar, true);
        }
    );
    return implode('', $kata);
}

function merge_nama_sama(string $a, string $b): bool {
    if (strtolower(trim($a)) === strtolower(trim($b))) {
        return true;
    }
    $na = merge_norm_nama($a);
    $nb = merge_norm_nama($b);
    if ($na === '' || $nb === '') {
        return false;
    }
    if ($na === $nb) {
        return true;
    }
    $min = min(strlen($na), strlen($nb));
    if ($min >= 10 && (str_starts_with($na, $nb) || str_starts_with($nb, $na))) {
        return true;
    }
    similar_text($na, $nb, $pct);
    return ($pct / 100) >= 0.88;
}

function merge_cari_grup(mysqli $db): array {
    $rows = [];
    $res = $db->query("
        SELECT p.id, p.nama_penyuluh, p.aktif, k.nama_kabupaten,
               (SELECT COUNT(*) FROM data_nte WHERE penyuluh_id=p.id) AS jml_trx,
               (SELECT COALESCE(SUM(nte_kth),0) FROM data_nte WHERE penyuluh_id=p.id) AS total_nte,
               (SELECT COUNT(*) FROM target_nte WHERE penyuluh_id=p.id) AS jml_target
        FROM penyuluh p
        JOIN kabupaten k ON p.kabupaten_id=k.id
        ORDER BY p.nama_penyuluh
    ");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[(int) $r['id']] = $r;
        }
    }

    // Union-find berdasar kecocokan nama.
    $parent = [];
    foreach ($rows as $id => $_) {
        $parent[$id] = $id;
    }
    $find = static function ($x) use (&$parent, &$find): int {
        while ($parent[$x] !== $x) {
            $parent[$x] = $parent[$parent[$x]];
            $x = $parent[$x];
        }
        return $x;
    };
    $ids = array_keys($rows);
    $n = count($ids);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            if (merge_nama_sama($rows[$ids[$i]]['nama_penyuluh'], $rows[$ids[$j]]['nama_penyuluh'])) {
                $parent[$find($ids[$i])] = $find($ids[$j]);
            }
        }
    }

    $grup = [];
    foreach ($rows as $id => $r) {
        $root = $find($id);
        $grup[$root][] = $r;
    }
    // Hanya grup berisi >1 anggota.
    $grup = array_values(array_filter($grup, static fn($g) => count($g) > 1));
    // Urutkan anggota: transaksi terbanyak dulu (calon utama dipertahankan).
    foreach ($grup as &$g) {
        usort($g, static fn($a, $b) => (int) $b['jml_trx'] <=> (int) $a['jml_trx']);
    }
    unset($g);
    return $grup;
}

// ── Eksekusi gabung ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'merge') {
    $keepId = (int) ($_POST['keep_id'] ?? 0);
    $mergeIds = array_unique(array_map('intval', (array) ($_POST['merge_ids'] ?? [])));
    $mergeIds = array_values(array_filter($mergeIds, static fn($id) => $id > 0 && $id !== $keepId));

    if ($keepId < 1 || !$mergeIds) {
        $msg = 'Pilih minimal 1 duplikat untuk digabungkan.'; $msgType = 'warning';
    } else {
        // Validasi: semua ID harus dalam SATU grup duplikat yang terdeteksi.
        $grup = merge_cari_grup($db);
        $valid = false;
        $keepRow = null;
        foreach ($grup as $g) {
            $gIds = array_map(static fn($r) => (int) $r['id'], $g);
            if (in_array($keepId, $gIds, true) && !array_diff($mergeIds, $gIds)) {
                $valid = true;
                foreach ($g as $r) {
                    if ((int) $r['id'] === $keepId) {
                        $keepRow = $r;
                    }
                }
                break;
            }
        }

        if (!$valid) {
            $msg = 'Data tidak valid: ID yang dipilih bukan satu grup duplikat.'; $msgType = 'danger';
        } else {
            $in = implode(',', $mergeIds);
            $db->begin_transaction();
            try {
                $pindahTrx = 0; $pindahTarget = 0; $targetBentrok = 0;

                // Target memiliki UNIQUE (penyuluh_id, jenis_produk_id, tahun).
                // Target sumber yang bentrok dengan target nama yang dipertahankan
                // dihapus (nilai target nama utama yang dipakai).
                $conflictIds = [];
                $resKonflik = $db->query("
                    SELECT t_src.id
                    FROM target_nte t_src
                    JOIN target_nte t_keep
                      ON t_keep.penyuluh_id = $keepId
                     AND t_keep.jenis_produk_id = t_src.jenis_produk_id
                     AND t_keep.tahun = t_src.tahun
                    WHERE t_src.penyuluh_id IN ($in)
                      AND t_src.jenis_produk_id IS NOT NULL
                ");
                if ($resKonflik) {
                    while ($rc = $resKonflik->fetch_assoc()) {
                        $conflictIds[] = (int) $rc['id'];
                    }
                }
                if ($conflictIds) {
                    $inKonflik = implode(',', $conflictIds);
                    $db->query("DELETE FROM target_nte WHERE id IN ($inKonflik)");
                    $targetBentrok = $db->affected_rows;
                }

                $db->query("UPDATE data_nte SET penyuluh_id=$keepId WHERE penyuluh_id IN ($in)");
                $pindahTrx = $db->affected_rows;

                $db->query("UPDATE target_nte SET penyuluh_id=$keepId WHERE penyuluh_id IN ($in)");
                $pindahTarget = $db->affected_rows;

                $db->query("DELETE FROM penyuluh WHERE id IN ($in)");
                $hapus = $db->affected_rows;

                $db->commit();
                $msg = "Digabungkan ke <strong>" . h($keepRow['nama_penyuluh']) . "</strong>: "
                    . "$hapus baris duplikat dihapus, $pindahTrx transaksi dan $pindahTarget target dipindahkan."
                    . ($targetBentrok > 0 ? " $targetBentrok target kembar dihapus (memakai target nama utama)." : '');
                $msgType = 'success';
            } catch (Throwable $e) {
                $db->rollback();
                $msg = 'Gagal menggabungkan: ' . h($e->getMessage());
                $msgType = 'danger';
            }
        }
    }
}

$grupDuplikat = merge_cari_grup($db);

require_once 'header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
  <div>
    <h2 class="page-title" style="font-size:20px;margin-bottom:2px;">Gabung Duplikat Penyuluh</h2>
    <p class="text-muted mb-0" style="font-size:12.5px;">Nama yang ejaannya mirip (beda kapital/gelar/typo) dikelompokkan. Pilih satu yang dipertahankan, sisanya digabung: transaksi &amp; target ikut pindah.</p>
  </div>
  <a href="master_penyuluh.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Master Penyuluh
  </a>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?=$msgType?> alert-dismissible fade show mb-4" role="alert">
  <i class="bi bi-<?=$msgType=='success'?'check-circle-fill':($msgType=='danger'?'x-circle-fill':'exclamation-triangle-fill')?> me-2"></i><?=$msg?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!$grupDuplikat): ?>
<div class="card">
  <div class="card-body text-center py-5">
    <i class="bi bi-check-circle-fill text-success" style="font-size:48px;"></i>
    <div class="fw-semibold mt-2" style="font-size:14px;">Tidak ada duplikat terdeteksi</div>
    <p class="text-muted mb-0" style="font-size:12px;">Semua nama di master penyuluh sudah unik.</p>
  </div>
</div>
<?php else: ?>
<div class="alert alert-warning mb-4" style="font-size:12.5px;">
  <i class="bi bi-exclamation-triangle-fill me-1"></i>
  Ditemukan <strong><?=count($grupDuplikat)?> grup</strong> duplikat. Periksa tiap grup sebelum menggabungkan — penggabungan tidak dapat dibatalkan.
</div>
<?php foreach ($grupDuplikat as $gi => $g): ?>
<div class="card mb-3">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill text-warning"></i>
    <span class="fw-semibold">Grup <?=($gi+1)?> — <?=count($g)?> nama mirip</span>
  </div>
  <div class="card-body p-0">
    <form method="POST" onsubmit="return confirm('Gabungkan duplikat grup ini? Transaksi & target ikut pindah ke nama yang dipertahankan.');">
      <input type="hidden" name="action" value="merge">
      <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:13px;">
          <thead>
            <tr>
              <th style="width:120px;text-align:center;">Pertahankan</th>
              <th>Nama Penyuluh</th>
              <th>Kabupaten</th>
              <th style="text-align:center;">Status</th>
              <th style="text-align:center;">Transaksi</th>
              <th style="text-align:right;">Total NTE</th>
              <th style="text-align:center;">Target</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($g as $ai => $r): ?>
            <tr>
              <td style="text-align:center;">
                <div class="form-check d-inline-block">
                  <input class="form-check-input" type="radio" name="keep_id" value="<?=(int)$r['id']?>" <?= $ai===0?'checked':'' ?> title="Pertahankan nama ini">
                </div>
                <div class="form-check d-inline-block ms-2" title="Gabungkan (hapus) baris ini">
                  <input class="form-check-input border-danger" type="checkbox" name="merge_ids[]" value="<?=(int)$r['id']?>" <?= $ai===0?'':'checked' ?>>
                </div>
              </td>
              <td class="fw-semibold"><?=h($r['nama_penyuluh'])?></td>
              <td><span class="badge bg-light text-dark border"><?=h($r['nama_kabupaten'])?></span></td>
              <td style="text-align:center;"><?= $r['aktif'] ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-danger">Nonaktif</span>' ?></td>
              <td style="text-align:center;" class="tabular-nums"><?=number_format((int)$r['jml_trx'], 0, ',', '.')?></td>
              <td style="text-align:right;" class="tabular-nums"><?=rupiah((float)$r['total_nte'])?></td>
              <td style="text-align:center;" class="tabular-nums"><?=number_format((int)$r['jml_target'], 0, ',', '.')?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="p-3 d-flex align-items-center gap-2 flex-wrap" style="border-top:1px solid #e2e5e9;">
        <small class="text-muted me-auto" style="font-size:11.5px;">Radio = dipertahankan &bull; Centang = digabung/dihapus</small>
        <button type="submit" class="btn btn-warning btn-sm">
          <i class="bi bi-intersect me-1"></i>Gabungkan Grup Ini
        </button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
