<?php
require_once 'config.php';
$pageTitle = 'Input Target NTE';
$db = getDB();

$colTj = $db->query("SHOW COLUMNS FROM target_nte LIKE 'target_jumlah_produksi'");
if ($colTj && $colTj->num_rows === 0) {
    $db->query("ALTER TABLE target_nte ADD COLUMN target_jumlah_produksi DECIMAL(18,4) NULL DEFAULT NULL COMMENT 'Target volume/jumlah produksi' AFTER target_nte, ADD COLUMN satuan_produksi VARCHAR(50) NULL DEFAULT NULL COMMENT 'Satuan target produksi' AFTER target_jumlah_produksi");
}

$msg = ''; $msgType = '';

// Handle POST - simpan target
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_target') {
        $penyuluh_id = (int)$_POST['penyuluh_id'];
        $jenis_produk_id = !empty($_POST['jenis_produk_id']) ? (int)$_POST['jenis_produk_id'] : null;
        $tahun = (int)$_POST['tahun'];
        $target_nte = parse_localized_number($_POST['target_nte'] ?? '0');
        $target_jumlah = parse_localized_number($_POST['target_jumlah_produksi'] ?? '0');
        $satuan_produksi = trim($_POST['satuan_produksi'] ?? '');
        $keterangan = trim($_POST['keterangan'] ?? '');

        if ($target_jumlah > 0 && $satuan_produksi === '') {
            $msg = 'Jika mengisi target jumlah produksi, isi juga satuan (mis. kg, ton, m3).';
            $msgType = 'danger';
        } elseif (!$penyuluh_id || !$tahun || ($target_nte <= 0 && $target_jumlah <= 0)) {
            $msg = 'Isi minimal salah satu: target NTE (Rp) atau target jumlah produksi (lebih dari 0).';
            $msgType = 'danger';
        } else {
            $target_jumlah_db = $target_jumlah > 0 ? $target_jumlah : null;
            $satuan_db = ($target_jumlah > 0 && $satuan_produksi !== '') ? $satuan_produksi : null;

            $stmt = $db->prepare("SELECT id FROM target_nte WHERE penyuluh_id=? AND tahun=? AND ((? IS NULL AND jenis_produk_id IS NULL) OR jenis_produk_id=?)");
            $stmt->bind_param('iiii', $penyuluh_id, $tahun, $jenis_produk_id, $jenis_produk_id);
            $stmt->execute();
            $check = $stmt->get_result();
            if ($check->num_rows > 0) {
                $existing_id = (int) $check->fetch_assoc()['id'];
                $stmt = $db->prepare("UPDATE target_nte SET target_nte=?, target_jumlah_produksi=?, satuan_produksi=?, keterangan=?, updated_at=NOW() WHERE id=?");
                $stmt->bind_param('ddssi', $target_nte, $target_jumlah_db, $satuan_db, $keterangan, $existing_id);
                $stmt->execute();
                $msg = 'Target berhasil diperbarui.';
            } else {
                $stmt = $db->prepare("INSERT INTO target_nte (penyuluh_id, jenis_produk_id, tahun, target_nte, target_jumlah_produksi, satuan_produksi, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('iiiddss', $penyuluh_id, $jenis_produk_id, $tahun, $target_nte, $target_jumlah_db, $satuan_db, $keterangan);
                $stmt->execute();
                $msg = 'Target berhasil disimpan.';
            }
            $msgType = 'success';
        }
    } elseif ($action === 'delete_target') {
        $del_id = (int)$_POST['target_id'];
        $stmt = $db->prepare("DELETE FROM target_nte WHERE id=?");
        $stmt->bind_param('i', $del_id);
        $stmt->execute();
        $msg = 'Target berhasil dihapus.'; $msgType = 'success';
    }
}

// Filter
$filter_tahun = isset($_GET['tahun']) ? (int) $_GET['tahun'] : (int) date('Y');
$filter_penyuluh = isset($_GET['penyuluh_id']) ? (int)$_GET['penyuluh_id'] : 0;
$filter_kab = isset($_GET['kab_id']) ? (int)$_GET['kab_id'] : 0;

// Data penyuluh
$penyuluh_list = $db->query("SELECT p.id, p.nama_penyuluh, k.nama_kabupaten FROM penyuluh p JOIN kabupaten k ON p.kabupaten_id=k.id WHERE p.aktif=1 ORDER BY k.nama_kabupaten, p.nama_penyuluh");
$penyuluh_rows = $penyuluh_list ? $penyuluh_list->fetch_all(MYSQLI_ASSOC) : [];
$kabupaten_list = $db->query("SELECT id, nama_kabupaten FROM kabupaten ORDER BY nama_kabupaten");
$produk_list = $db->query("SELECT jp.id, jp.nama_produk, kp.nama_kategori FROM jenis_produk jp LEFT JOIN kategori_produk kp ON jp.kategori_id=kp.id ORDER BY kp.nama_kategori, jp.nama_produk");

// Target list
$where = "WHERE t.tahun=$filter_tahun";
if ($filter_penyuluh) $where .= " AND t.penyuluh_id=$filter_penyuluh";
if ($filter_kab) $where .= " AND k.id=$filter_kab";

$target_list = $db->query("
  SELECT t.*, p.nama_penyuluh, k.nama_kabupaten, jp.nama_produk,
    (
      SELECT COALESCE(SUM(d2.nte_kth), 0)
      FROM data_nte d2
      WHERE d2.penyuluh_id = t.penyuluh_id
        AND CAST(d2.tahun AS UNSIGNED) = CAST(t.tahun AS UNSIGNED)
        AND (
          t.jenis_produk_id IS NULL
          OR d2.jenis_produk_id <=> t.jenis_produk_id
          OR (
            t.jenis_produk_id IS NOT NULL
            AND d2.jenis_produk_id IS NULL
            AND d2.kategori_id IS NOT NULL
            AND d2.kategori_id <=> (SELECT jp2.kategori_id FROM jenis_produk jp2 WHERE jp2.id = t.jenis_produk_id LIMIT 1)
          )
          OR (
            t.jenis_produk_id IS NOT NULL
            AND d2.jenis_produk_id IN (
              SELECT jpa.id FROM jenis_produk jpa
              WHERE jpa.nama_produk <=> (SELECT jpb.nama_produk FROM jenis_produk jpb WHERE jpb.id = t.jenis_produk_id LIMIT 1)
            )
          )
          OR (
            t.jenis_produk_id IS NOT NULL
            AND (
              SELECT jpt.nama_produk <=> kpt.nama_kategori
              FROM jenis_produk jpt
              LEFT JOIN kategori_produk kpt ON jpt.kategori_id = kpt.id
              WHERE jpt.id = t.jenis_produk_id
              LIMIT 1
            )
            AND d2.kategori_id IS NOT NULL
            AND d2.kategori_id <=> (SELECT jpc.kategori_id FROM jenis_produk jpc WHERE jpc.id = t.jenis_produk_id LIMIT 1)
          )
        )
    ) AS realisasi,
    (
      SELECT COALESCE(SUM(d3.jumlah_penjualan), 0)
      FROM data_nte d3
      WHERE d3.penyuluh_id = t.penyuluh_id
        AND CAST(d3.tahun AS UNSIGNED) = CAST(t.tahun AS UNSIGNED)
        AND (
          t.jenis_produk_id IS NULL
          OR d3.jenis_produk_id <=> t.jenis_produk_id
          OR (
            t.jenis_produk_id IS NOT NULL
            AND d3.jenis_produk_id IS NULL
            AND d3.kategori_id IS NOT NULL
            AND d3.kategori_id <=> (SELECT jp3.kategori_id FROM jenis_produk jp3 WHERE jp3.id = t.jenis_produk_id LIMIT 1)
          )
          OR (
            t.jenis_produk_id IS NOT NULL
            AND d3.jenis_produk_id IN (
              SELECT jpa.id FROM jenis_produk jpa
              WHERE jpa.nama_produk <=> (SELECT jpb.nama_produk FROM jenis_produk jpb WHERE jpb.id = t.jenis_produk_id LIMIT 1)
            )
          )
          OR (
            t.jenis_produk_id IS NOT NULL
            AND (
              SELECT jpt.nama_produk <=> kpt.nama_kategori
              FROM jenis_produk jpt
              LEFT JOIN kategori_produk kpt ON jpt.kategori_id = kpt.id
              WHERE jpt.id = t.jenis_produk_id
              LIMIT 1
            )
            AND d3.kategori_id IS NOT NULL
            AND d3.kategori_id <=> (SELECT jpc.kategori_id FROM jenis_produk jpc WHERE jpc.id = t.jenis_produk_id LIMIT 1)
          )
        )
    ) AS realisasi_jumlah,
    (
      SELECT COALESCE(SUM(dx.nte_kth), 0)
      FROM data_nte dx
      WHERE dx.penyuluh_id = t.penyuluh_id
        AND CAST(dx.tahun AS UNSIGNED) = CAST(t.tahun AS UNSIGNED)
    ) AS realisasi_total_tahun
  FROM target_nte t
  JOIN penyuluh p ON t.penyuluh_id=p.id
  JOIN kabupaten k ON p.kabupaten_id=k.id
  LEFT JOIN jenis_produk jp ON t.jenis_produk_id=jp.id
  $where
  ORDER BY k.nama_kabupaten, p.nama_penyuluh, jp.nama_produk
");

$tahun_options = range(date('Y')-2, date('Y')+2);

require_once 'header.php';


?>

<script>
window.tailwind = window.tailwind || {};
window.tailwind.config = { corePlugins: { preflight: false } };
</script>
<script src="https://cdn.tailwindcss.com"></script>

<div class="d-flex justify-content-end align-items-center mb-4">
  <div class="d-flex flex-wrap gap-2 justify-content-end">
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#targetModal" onclick="resetTargetForm()">
      <i class="bi bi-plus-lg me-1"></i>Tambah Target
    </button>
    <a href="import_target.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-arrow-up me-1"></i>Import Target</a>
  </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?=$msgType?> alert-dismissible fade show">
  <i class="bi bi-<?=$msgType=='success'?'check-circle-fill':'x-circle-fill'?> me-2"></i><?=$msg?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="modal fade" id="targetModal" tabindex="-1" aria-labelledby="targetModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-0 pb-0">
        <div>
          <h5 class="modal-title fw-600" id="targetModalLabel">Tambah Target</h5>
          <p class="text-muted mb-0" style="font-size:12px">Isi target nilai NTE dan/atau target produksi per penyuluh.</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <form method="POST" id="targetForm">
        <div class="modal-body pt-3">
          <input type="hidden" name="action" value="save_target">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1.5">Tahun <span class="text-red-500">*</span></label>
              <select name="tahun" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-white text-gray-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" required>
                <?php foreach($tahun_options as $y): ?>
                <option value="<?=$y?>" <?=$y==date('Y')?'selected':''?>><?=$y?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1.5">Penyuluh <span class="text-red-500">*</span></label>
              <select name="penyuluh_id" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-white text-gray-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" required id="penyuluhSelect">
                <option value="">-- Pilih Penyuluh --</option>
                <?php
                $kab_group = '';
                foreach($penyuluh_rows as $p):
                  if($p['nama_kabupaten'] !== $kab_group):
                    if($kab_group) echo '</optgroup>';
                    echo '<optgroup label="'.h($p['nama_kabupaten']).'">';
                    $kab_group = $p['nama_kabupaten'];
                  endif;
                ?>
                  <option value="<?=$p['id']?>"><?=h($p['nama_penyuluh'])?></option>
                <?php endforeach; if($kab_group) echo '</optgroup>'; ?>
              </select>
            </div>

            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1.5">Jenis Produk <small class="text-gray-400">(opsional - kosongkan untuk semua produk)</small></label>
              <select name="jenis_produk_id" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-white text-gray-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" id="produkSelect">
                <option value="">-- Semua Produk --</option>
                <?php
                $kat_group = '';
                $produk_list->data_seek(0);
                while($pr=$produk_list->fetch_assoc()):
                  $kat = $pr['nama_kategori'] ?: 'Lainnya';
                  if($kat !== $kat_group):
                    if($kat_group) echo '</optgroup>';
                    echo '<optgroup label="'.htmlspecialchars($kat).'">';
                    $kat_group = $kat;
                  endif;
                ?>
                  <option value="<?=$pr['id']?>"><?=htmlspecialchars($pr['nama_produk'])?></option>
                <?php endwhile; if($kat_group) echo '</optgroup>'; ?>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1.5">Target NTE (Rp.)</label>
              <div class="flex rounded-lg border border-gray-300 overflow-hidden focus-within:ring-2 focus-within:ring-emerald-500 focus-within:border-transparent transition-all">
                <span class="flex items-center px-3 bg-gray-50 text-sm text-gray-500 border-r border-gray-300 font-medium select-none">Rp</span>
                <input type="text" name="target_nte" class="flex-1 px-3 py-2 text-sm text-gray-800 bg-white outline-none placeholder-gray-400 min-w-0" id="targetInput" placeholder="0" oninput="formatRupiah(this)">
              </div>
              <div id="targetReadable" class="mt-1 text-xs text-emerald-700"></div>
              <p class="mt-1 text-xs text-gray-400 mb-0">Kosongkan atau 0 jika hanya target produksi.</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1.5">Target Jumlah Produksi</label>
              <div class="flex rounded-lg border border-gray-300 overflow-hidden focus-within:ring-2 focus-within:ring-emerald-500 focus-within:border-transparent transition-all">
                <input type="text" name="target_jumlah_produksi" class="flex-1 px-3 py-2 text-sm text-gray-800 bg-white outline-none border-r border-gray-300 min-w-0" id="targetJumlahInput" placeholder="0" inputmode="decimal">
                <select name="satuan_produksi" class="px-3 py-2 text-sm text-gray-700 bg-gray-50 outline-none min-w-[90px]" id="satuanProduksi">
                  <option value="">Satuan</option>
                  <option value="kg">kg</option>
                  <option value="ton">ton</option>
                  <option value="m3">m3</option>
                  <option value="batang">batang</option>
                  <option value="liter">liter</option>
                  <option value="ekor">ekor</option>
                  <option value="ikat">ikat</option>
                  <option value="buah">buah</option>
                </select>
              </div>
              <p class="mt-1 text-xs text-gray-400 mb-0">Isi bersama satuan jika memakai target produksi.</p>
            </div>

            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1.5">Keterangan</label>
              <textarea name="keterangan" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-white text-gray-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" rows="2" placeholder="Opsional..."></textarea>
            </div>
          </div>
          <p class="text-muted small mb-0 mt-3" style="font-size:11px">Minimal salah satu: nilai Rp atau jumlah produksi, harus lebih dari 0.</p>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-2"></i>Simpan Target</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div>
    <!-- Filter -->
    <form method="GET" class="flex flex-wrap items-end gap-3 p-4 bg-white rounded-xl border border-gray-200 shadow-sm mb-4" data-disable-on-submit>
          <div class="flex flex-col gap-1 min-w-[100px]">
            <label class="text-xs font-medium text-gray-500">Tahun</label>
            <select name="tahun" class="h-9 px-3 py-0 text-sm border border-gray-300 rounded-lg bg-white text-gray-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent cursor-pointer">
              <?php foreach($tahun_options as $y): ?>
              <option value="<?=$y?>" <?=$y==$filter_tahun?'selected':''?>><?=$y?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="flex flex-col gap-1 min-w-[180px]">
            <label class="text-xs font-medium text-gray-500">Kabupaten</label>
            <select name="kab_id" class="h-9 px-3 py-0 text-sm border border-gray-300 rounded-lg bg-white text-gray-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent cursor-pointer">
              <option value="">Semua Kabupaten</option>
              <?php $kabupaten_list->data_seek(0); while($k=$kabupaten_list->fetch_assoc()): ?>
              <option value="<?=$k['id']?>" <?=$k['id']==$filter_kab?'selected':''?>><?=$k['nama_kabupaten']?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="flex flex-col gap-1 min-w-[180px]">
            <label class="text-xs font-medium text-gray-500">Penyuluh</label>
            <select name="penyuluh_id" class="h-9 px-3 py-0 text-sm border border-gray-300 rounded-lg bg-white text-gray-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent cursor-pointer">
              <option value="">Semua Penyuluh</option>
              <?php
              $filter_kab_group = '';
              foreach($penyuluh_rows as $p):
                if($p['nama_kabupaten'] !== $filter_kab_group):
                  if($filter_kab_group) echo '</optgroup>';
                  echo '<optgroup label="'.h($p['nama_kabupaten']).'">';
                  $filter_kab_group = $p['nama_kabupaten'];
                endif;
              ?>
                <option value="<?=$p['id']?>" <?=$p['id']==$filter_penyuluh?'selected':''?>><?=h($p['nama_penyuluh'])?></option>
              <?php endforeach; if($filter_kab_group) echo '</optgroup>'; ?>
            </select>
          </div>
          <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-transparent select-none">_</label>
            <button type="submit" class="flex items-center gap-2 px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white text-sm font-medium rounded-lg transition-colors shadow-sm">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.35-4.35"></path>
              </svg>
              Filter
            </button>
          </div>
          <div class="flex items-end pb-0.5 ml-auto">
            <span class="text-xs text-gray-500 bg-gray-100 px-2.5 py-1.5 rounded-full font-medium h-9 flex items-center"><?=$target_list->num_rows?> data</span>
          </div>
    </form>

    <!-- Target Table -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
        <div class="flex items-center gap-2">
          <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
          <h2 class="text-sm font-semibold text-gray-800 mb-0">Daftar Target NTE Tahun <?=$filter_tahun?></h2>
        </div>
      </div>
      <div>
        <?php if ($target_list->num_rows == 0): ?>
          <div class="empty-state">
            <i class="bi bi-bullseye"></i>
            <div class="empty-state-title">Belum ada target untuk filter ini</div>
            <p class="empty-state-text">Tambahkan target dari formulir di sisi kiri, atau ubah filter tahun, kabupaten, dan penyuluh.</p>
          </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="min-w-full">
            <thead>
              <tr class="bg-gray-50 border-b border-gray-200">
                <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide" style="min-width:160px">Penyuluh</th>
                <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Kab.</th>
                <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide" style="min-width:170px">Produk</th>
                <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Target Rp</th>
                <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Target Prod.</th>
                <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Real. Rp</th>
                <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Real. Prod.</th>
                <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide w-32">% NTE</th>
                <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide w-16">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <?php while($t=$target_list->fetch_assoc()):
                $pct = $t['target_nte'] > 0 ? ($t['realisasi'] / $t['target_nte'] * 100) : 0;
                $pct_width = $pct > 100 ? 100 : ($pct > 0 && $pct < 2 ? 2 : $pct);
                $bar_class = $pct >= 100 ? 'bg-emerald-500 text-emerald-600' : ($pct >= 75 ? 'bg-blue-500 text-blue-600' : ($pct >= 50 ? 'bg-yellow-500 text-yellow-600' : 'bg-red-500 text-red-600'));
                [$bar_bg, $bar_text] = explode(' ', $bar_class);
                $tj = isset($t['target_jumlah_produksi']) ? (float) $t['target_jumlah_produksi'] : 0;
                $sat = trim((string) ($t['satuan_produksi'] ?? ''));
                $rj = isset($t['realisasi_jumlah']) ? (float) $t['realisasi_jumlah'] : 0;
                $r_total = isset($t['realisasi_total_tahun']) ? (float) $t['realisasi_total_tahun'] : 0;
                $pct_vol = ($tj > 0) ? ($rj / $tj * 100) : null;
                $pct_vol_width = $pct_vol === null ? 0 : ($pct_vol > 100 ? 100 : ($pct_vol > 0 && $pct_vol < 2 ? 2 : $pct_vol));
                $bar_vol_class = $pct_vol === null ? 'bg-gray-300 text-gray-400' : ($pct_vol >= 100 ? 'bg-emerald-500 text-emerald-600' : ($pct_vol >= 75 ? 'bg-blue-500 text-blue-600' : ($pct_vol >= 50 ? 'bg-yellow-500 text-yellow-600' : 'bg-red-500 text-red-600')));
                [$bar_vol_bg, $bar_vol_text] = explode(' ', $bar_vol_class);
                $real_rp = (float) $t['realisasi'];
                $tip_real = '';
                if ($t['jenis_produk_id'] && $real_rp < 0.01 && $r_total > 0.01) {
                    $tip_real = 'Realisasi untuk produk target ini: Rp 0. Total NTE penyuluh (semua produk) tahun ini: Rp ' . format_tanpa_pembulatan($r_total, 2) . '. Periksa apakah jenis produk di transaksi sama dengan target (nama/ID).';
                }
              ?>
              <tr class="hover:bg-emerald-50/30 transition-colors">
                <td class="px-3 py-3 align-middle" style="min-width:160px">
                  <span class="text-sm font-semibold text-gray-800 capitalize leading-snug block"><?=htmlspecialchars($t['nama_penyuluh'])?></span>
                </td>
                <td class="px-3 py-3 align-middle">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-blue-50 text-blue-700 border border-blue-100 whitespace-nowrap"><?=h($t['nama_kabupaten'])?></span>
                </td>
                <td class="px-3 py-3 align-middle" style="min-width:170px">
                  <span class="text-sm text-gray-700 leading-snug"><?=$t['nama_produk'] ? h($t['nama_produk']) : '<span class="text-gray-400 italic">Semua</span>'?></span>
                </td>
                <td class="px-3 py-3 align-middle text-right whitespace-nowrap">
                  <span class="text-sm font-medium text-gray-900"><?= $t['target_nte'] > 0 ? 'Rp ' . format_tanpa_pembulatan($t['target_nte'], 2) : '<span class="text-gray-400">&mdash;</span>' ?></span>
                </td>
                <td class="px-3 py-3 align-middle text-right whitespace-nowrap"><span class="text-sm text-gray-700"><?php
                  if ($tj > 0 && $sat !== '') {
                      echo htmlspecialchars(format_tanpa_pembulatan($tj, 2)) . ' ' . htmlspecialchars($sat);
                  } else {
                      echo '<span class="text-gray-400">&mdash;</span>';
                  }
                ?></span></td>
                <td class="px-3 py-3 align-middle text-right whitespace-nowrap" <?= $tip_real !== '' ? 'title="' . htmlspecialchars($tip_real) . '"' : '' ?>>
                  <span class="text-sm text-gray-700">Rp <?= format_tanpa_pembulatan($real_rp, 2) ?></span>
                </td>
                <td class="px-3 py-3 align-middle text-right whitespace-nowrap"><span class="text-sm text-gray-700"><?php
                  echo htmlspecialchars(format_tanpa_pembulatan($rj, 2));
                  if ($sat !== '') {
                      echo ' ' . htmlspecialchars($sat);
                  }
                ?></span></td>
                <td class="px-3 py-3 align-middle w-32">
                  <?php if ($t['target_nte'] > 0): ?>
                  <div class="mb-1.5">
                    <div class="flex items-center justify-between mb-0.5">
                      <span class="text-[10px] text-gray-400">Rp</span>
                      <span class="text-[10px] font-semibold <?=$bar_text?>"><?=persen_tanpa_pembulatan($pct,2)?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-1.5">
                      <div class="<?=$bar_bg?> h-1.5 rounded-full transition-all" style="width:<?=$pct_width?>%"></div>
                    </div>
                  </div>
                  <?php endif; ?>
                  <?php if ($pct_vol !== null): ?>
                  <div title="Capaian jumlah produksi">
                    <div class="flex items-center justify-between mb-0.5">
                      <span class="text-[10px] text-gray-400">Prod.</span>
                      <span class="text-[10px] font-semibold <?=$bar_vol_text?>"><?=persen_tanpa_pembulatan($pct_vol,2)?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-1.5">
                      <div class="<?=$bar_vol_bg?> h-1.5 rounded-full transition-all" style="width:<?=$pct_vol_width?>%"></div>
                    </div>
                  </div>
                  <?php elseif ($t['target_nte'] <= 0): ?>
                  <span class="text-gray-400 text-xs">&mdash;</span>
                  <?php endif; ?>
                </td>
                <td class="px-3 py-3 align-middle text-center">
                  <div class="flex items-center justify-center gap-1 h-full">
                    <button type="button" title="Edit target"
                            class="p-1.5 text-gray-400 hover:text-emerald-700 hover:bg-emerald-50 rounded-md transition-colors"
                            data-edit-tahun="<?=h($t['tahun'])?>"
                            data-edit-penyuluh="<?=h($t['penyuluh_id'])?>"
                            data-edit-produk="<?=h($t['jenis_produk_id'] ?? '')?>"
                            data-edit-nte="<?=h(format_tanpa_pembulatan((float) $t['target_nte'], 2))?>"
                            data-edit-jumlah="<?=h($tj > 0 ? format_tanpa_pembulatan($tj, 2) : '')?>"
                            data-edit-satuan="<?=h($sat)?>"
                            data-edit-keterangan="<?=h($t['keterangan'] ?? '')?>"
                            onclick="fillTargetFormFromButton(this)">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                      </svg>
                    </button>
                  <form method="POST" class="mb-0" onsubmit="return confirm('Yakin hapus target ini?')">
                    <input type="hidden" name="action" value="delete_target">
                    <input type="hidden" name="target_id" value="<?=$t['id']?>">
                    <button type="submit" title="Hapus target" class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-md transition-colors">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v3M4 7h16"></path>
                      </svg>
                    </button>
                  </form>
                  </div>
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
</div>

<?php
$extraJS = <<<JS
<script>
function formatRupiah(el) {
  let raw = el.value.replace(/\D/g,'');
  el.value = raw.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const num = parseInt(raw)||0;
  const readable = document.getElementById('targetReadable');
  if(num>0) readable.textContent='= Rp '+num.toLocaleString('id-ID', { maximumFractionDigits: 2 });
  else readable.textContent='';
}
function fillTargetFormFromButton(btn) {
  const form = document.getElementById('targetForm');
  if (!form) return;
  document.getElementById('targetModalLabel').textContent = 'Edit Target';
  const setValue = (selector, value) => {
    const el = form.querySelector(selector);
    if (el) el.value = value || '';
  };
  setValue('[name="tahun"]', btn.dataset.editTahun);
  setValue('[name="penyuluh_id"]', btn.dataset.editPenyuluh);
  setValue('[name="jenis_produk_id"]', btn.dataset.editProduk);
  setValue('[name="target_nte"]', btn.dataset.editNte);
  setValue('[name="target_jumlah_produksi"]', btn.dataset.editJumlah);
  const satuan = form.querySelector('[name="satuan_produksi"]');
  if (satuan) {
    const value = btn.dataset.editSatuan || '';
    if (value && !Array.from(satuan.options).some(opt => opt.value === value)) {
      satuan.add(new Option(value, value));
    }
    satuan.value = value;
  }
  setValue('[name="keterangan"]', btn.dataset.editKeterangan);
  formatRupiah(document.getElementById('targetInput'));
  const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('targetModal'));
  modal.show();
}
function resetTargetForm() {
  const form = document.getElementById('targetForm');
  if (!form) return;
  form.reset();
  document.getElementById('targetModalLabel').textContent = 'Tambah Target';
  const readable = document.getElementById('targetReadable');
  if (readable) readable.textContent = '';
}
</script>
JS;
require_once 'footer.php';
?>



