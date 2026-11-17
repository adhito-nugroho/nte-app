<?php
require_once 'config.php';
$pageTitle = 'Status Penyuluh';
$db = getDB();

$tahun_filter = isset($_GET['tahun']) ? (int) $_GET['tahun'] : (int) date('Y');
$kab_filter = isset($_GET['kab_id']) ? (int)$_GET['kab_id'] : 0;
$status_filter = $_GET['status'] ?? 'semua';

$y_now = (int) date('Y');
$m_now = (int) date('n');
if ($tahun_filter < $y_now) {
    $bulan_akhir_monitor = 12;
} elseif ($tahun_filter === $y_now) {
    $bulan_akhir_monitor = $m_now;
} else {
    $bulan_akhir_monitor = 0;
}

$months_sql = '(SELECT 1 AS bln UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
  UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12)';

$bulan_labels = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des'];
$bulan_nama_id = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

$kabupaten_list = $db->query("SELECT id, nama_kabupaten FROM kabupaten ORDER BY nama_kabupaten");
$tahun_list = $db->query("SELECT DISTINCT tahun FROM data_nte ORDER BY tahun DESC");

$kab_cond = $kab_filter ? "AND k.id=$kab_filter" : "";

$penyuluh_data = $db->query("
  SELECT p.id, p.nama_penyuluh, k.nama_kabupaten, k.id as kab_id,
         COALESCE(d.total_nte, 0) as total_nte,
         COALESCE(d.jumlah_transaksi, 0) as jumlah_transaksi,
         COALESCE(d.bulan_input, '') as bulan_input,
         COALESCE(t.total_target, 0) as total_target,
         COALESCE(gap.bulan_belum, '') as bulan_belum,
         CASE
           WHEN $bulan_akhir_monitor < 1 THEN 'sudah'
           WHEN gap.penyuluh_id IS NULL THEN 'sudah'
           ELSE 'belum'
         END as status
  FROM penyuluh p
  JOIN kabupaten k ON p.kabupaten_id=k.id
  LEFT JOIN (
    SELECT penyuluh_id, SUM(nte_kth) as total_nte, COUNT(*) as jumlah_transaksi,
           GROUP_CONCAT(DISTINCT bulan ORDER BY bulan) as bulan_input
    FROM data_nte WHERE tahun=$tahun_filter
    GROUP BY penyuluh_id
  ) d ON d.penyuluh_id=p.id
  LEFT JOIN (
    SELECT penyuluh_id, SUM(target_nte) as total_target
    FROM target_nte WHERE tahun=$tahun_filter
    GROUP BY penyuluh_id
  ) t ON t.penyuluh_id=p.id
  LEFT JOIN (
    SELECT p2.id AS penyuluh_id, GROUP_CONCAT(mo.bln ORDER BY mo.bln) AS bulan_belum
    FROM penyuluh p2
    CROSS JOIN $months_sql mo
    WHERE p2.aktif=1
      AND mo.bln <= $bulan_akhir_monitor
      AND NOT EXISTS (
        SELECT 1 FROM data_nte d2
        WHERE d2.penyuluh_id = p2.id AND CAST(d2.tahun AS UNSIGNED) = $tahun_filter
          AND CAST(d2.bulan AS UNSIGNED) = mo.bln
      )
    GROUP BY p2.id
  ) gap ON gap.penyuluh_id = p.id
  WHERE p.aktif=1 $kab_cond
  " . ($status_filter=='sudah' ? "HAVING status='sudah'" : ($status_filter=='belum' ? "HAVING status='belum'" : "")) . "
  ORDER BY k.nama_kabupaten, status DESC, total_nte DESC
");

$rows_all = $penyuluh_data->fetch_all(MYSQLI_ASSOC);

// Rincian realisasi & target per produk per penyuluh (baris detail saat expand)
$detail_per_penyuluh = [];
$pids = array_map('intval', array_column($rows_all, 'id'));
if (!empty($pids)) {
    $in = implode(',', $pids);

    $qReal = $db->query("
      SELECT d.penyuluh_id,
        d.jenis_produk_id,
        COALESCE(MAX(jp.nama_produk), MAX(kp.nama_kategori), '&mdash;') AS nama_produk,
        MAX(kp.nama_kategori) AS nama_kategori,
        SUM(d.nte_kth) AS total_nte,
        COUNT(*) AS jumlah_transaksi
      FROM data_nte d
      LEFT JOIN jenis_produk jp ON d.jenis_produk_id = jp.id
      LEFT JOIN kategori_produk kp ON COALESCE(jp.kategori_id, d.kategori_id) = kp.id
      WHERE CAST(d.tahun AS UNSIGNED) = $tahun_filter AND d.penyuluh_id IN ($in)
      GROUP BY d.penyuluh_id, d.jenis_produk_id, d.kategori_id
    ");

    $qTgt = $db->query("
      SELECT t.id AS target_row_id, t.penyuluh_id, t.jenis_produk_id, t.target_nte,
        COALESCE(jp.nama_produk, 'Semua produk') AS nama_produk
      FROM target_nte t
      LEFT JOIN jenis_produk jp ON t.jenis_produk_id = jp.id
      WHERE t.tahun = $tahun_filter AND t.penyuluh_id IN ($in)
    ");

    $real_rows = [];
    if ($qReal) {
        while ($r = $qReal->fetch_assoc()) {
            $pid = (int) $r['penyuluh_id'];
            if (!isset($real_rows[$pid])) {
                $real_rows[$pid] = [];
            }
            $real_rows[$pid][] = $r;
        }
    }

    $tgt_rows = [];
    if ($qTgt) {
        while ($r = $qTgt->fetch_assoc()) {
            $pid = (int) $r['penyuluh_id'];
            if (!isset($tgt_rows[$pid])) {
                $tgt_rows[$pid] = [];
            }
            $tgt_rows[$pid][] = $r;
        }
    }

    foreach ($pids as $pid) {
        $merged = [];
        foreach ($real_rows[$pid] ?? [] as $r) {
            $jid = $r['jenis_produk_id'];
            if ($jid !== null && $jid !== '') {
                $key = 'jp' . (int) $jid;
            } else {
                $key = 'njp_' . md5($pid . '|' . ($r['nama_produk'] ?? '') . '|' . (string) ($r['nama_kategori'] ?? ''));
            }
            $merged[$key] = [
                'nama_produk' => $r['nama_produk'],
                'nama_kategori' => $r['nama_kategori'],
                'total_nte' => (float) $r['total_nte'],
                'jumlah_transaksi' => (int) $r['jumlah_transaksi'],
                'target_nte' => 0.0,
            ];
        }
        foreach ($tgt_rows[$pid] ?? [] as $t) {
            $jid = $t['jenis_produk_id'];
            if ($jid !== null && $jid !== '') {
                $key = 'jp' . (int) $jid;
            } else {
                $key = 'tgt' . (int) $t['target_row_id'];
            }
            $tv = (float) $t['target_nte'];
            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'nama_produk' => $t['nama_produk'],
                    'nama_kategori' => '',
                    'total_nte' => 0.0,
                    'jumlah_transaksi' => 0,
                    'target_nte' => $tv,
                ];
            } else {
                $merged[$key]['target_nte'] = (float) ($merged[$key]['target_nte'] ?? 0) + $tv;
            }
        }
        uasort($merged, function ($a, $b) {
            return strcasecmp($a['nama_produk'] ?? '', $b['nama_produk'] ?? '');
        });
        $detail_per_penyuluh[$pid] = array_values($merged);
    }
}

require_once 'header.php';



?>

<!-- Filter -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label mb-1" style="font-size:12px">Tahun</label>
        <select name="tahun" class="form-select form-select-sm">
          <?php while($ty=$tahun_list->fetch_assoc()): ?>
          <option value="<?=$ty['tahun']?>" <?=$ty['tahun']==$tahun_filter?'selected':''?>><?=$ty['tahun']?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:12px">Kabupaten</label>
        <select name="kab_id" class="form-select form-select-sm">
          <option value="">Semua Kabupaten</option>
          <?php $kabupaten_list->data_seek(0); while($k=$kabupaten_list->fetch_assoc()): ?>
          <option value="<?=$k['id']?>" <?=$k['id']==$kab_filter?'selected':''?>><?=$k['nama_kabupaten']?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:12px">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="semua" <?=$status_filter=='semua'?'selected':''?>>Semua Penyuluh</option>
          <option value="sudah" <?=$status_filter=='sudah'?'selected':''?>>Sudah Input</option>
          <option value="belum" <?=$status_filter=='belum'?'selected':''?>>Belum Input</option>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Filter</button>
      </div>
      <div class="col-md-2">
        <a href="laporan_penyuluh.php?tahun=<?=$tahun_filter?>" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Summary cards -->
<?php
$total_all = count($rows_all);
$sudah_all = count(array_filter($rows_all, fn($r)=>$r['status']=='sudah'));
$belum_all = $total_all - $sudah_all;
$total_nte_all = array_sum(array_column($rows_all, 'total_nte'));
?>
<div class="row g-3 mb-4">
  <div class="col-sm-3">
    <div class="stat-card text-center">
      <div class="stat-value"><?=$total_all?></div>
      <div class="stat-label">Total Penyuluh</div>
    </div>
  </div>
  <div class="col-sm-3">
    <div class="stat-card text-center" style="border-color:#c6f6d5">
      <div class="stat-value" style="color:#276749"><?=$sudah_all?></div>
      <div class="stat-label">Sudah Input</div>
      <div class="mt-1"><div class="progress" style="height:4px"><div class="progress-bar" style="background:#276749;width:<?=$total_all?($sudah_all/$total_all*100):0?>%"></div></div></div>
    </div>
  </div>
  <div class="col-sm-3">
    <div class="stat-card text-center" style="border-color:#fed7d7">
      <div class="stat-value" style="color:#c53030"><?=$belum_all?></div>
      <div class="stat-label">Belum Input</div>
    </div>
  </div>
  <div class="col-sm-3">
    <div class="stat-card text-center">
      <div class="stat-value" style="font-size:18px"><?=rupiah($total_nte_all)?></div>
      <div class="stat-label">Total NTE <?=$tahun_filter?></div>
    </div>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h6 class="mb-0"><i class="bi bi-people me-2 text-success"></i>Detail Penyuluh Tahun <?=$tahun_filter?></h6>
      <div class="text-muted mt-1" style="font-size:11px">Baris utama menampilkan total; klik nama penyuluh untuk rincian realisasi &amp; target per produk.</div>
    </div>
    <span class="badge bg-light text-dark border"><?=$total_all?> penyuluh</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-clean table-hover mb-0 penyuluh-status-table">
        <thead>
          <tr>
            <th class="ps-3 col-no">#</th>
            <th class="col-name">Nama Penyuluh</th>
            <th>Kabupaten</th>
            <th>Status</th>
            <th>Bulan Belum Input</th>
            <th>Bulan Sudah Input</th>
            <th class="text-end">Transaksi</th>
            <th class="text-end">Realisasi NTE</th>
            <th class="text-end">Target NTE</th>
            <th class="col-progress">Capaian</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows_all as $i=>$p): 
            $pct = $p['total_target']>0 ? ($p['total_nte']/$p['total_target']*100) : null;
            $bar_color = $pct!==null?($pct>=100?'#276749':($pct>=50?'var(--primary)':'#e53e3e')):'#cbd5e0';
            $pid = (int) $p['id'];
            $collapse_id = 'penyuluh-det-' . $pid;
            $detail_rows = $detail_per_penyuluh[$pid] ?? [];
          ?>
          <tr class="penyuluh-summary-row align-middle">
            <td class="ps-3 text-muted col-no"><?=$i+1?></td>
            <td>
              <a href="javascript:void(0)"
                 class="penyuluh-name-link penyuluh-detail-toggle"
                 data-bs-toggle="collapse"
                 data-bs-target="#<?=htmlspecialchars($collapse_id)?>"
                 role="button"
                 aria-expanded="false"
                 aria-controls="<?=htmlspecialchars($collapse_id)?>">
                <span class="penyuluh-chevron-wrap"><i class="bi bi-chevron-down penyuluh-chevron"></i></span>
                <span>
                  <span class="penyuluh-name"><?=htmlspecialchars($p['nama_penyuluh'])?></span>
                  <span class="penyuluh-hint">Klik untuk rincian produk</span>
                </span>
              </a>
            </td>
            <td><span class="area-badge"><?=h($p['nama_kabupaten'])?></span></td>
            <td>
              <?php if($p['status']=='sudah'): ?>
                <span class="status-pill status-ok"><i class="bi bi-check-circle"></i>Sudah</span>
              <?php else: ?>
                <span class="status-pill status-missing"><i class="bi bi-x-circle"></i>Belum</span>
              <?php endif; ?>
            </td>
            <td>
              <?php
              if (!empty($p['bulan_belum'])) {
                  echo '<div class="month-chip-list">';
                  foreach (explode(',', $p['bulan_belum']) as $b) {
                      $b = (int) $b;
                      echo '<span class="month-chip month-missing" title="' . htmlspecialchars($bulan_nama_id[$b] ?? (string) $b) . '">'
                          . htmlspecialchars($bulan_labels[$b] ?? (string) $b) . '</span>';
                  }
                  echo '</div>';
              } else {
                  ?><span class="table-empty">Lengkap</span><?php
              }
              ?>
            </td>
            <td>
              <?php if($p['bulan_input']): ?>
                <div class="month-chip-list">
                <?php foreach(explode(',', $p['bulan_input']) as $b):
                  $b = (int) $b;
                  echo '<span class="month-chip month-done" title="' . htmlspecialchars($bulan_nama_id[$b] ?? (string) $b) . '">' . htmlspecialchars($bulan_labels[$b] ?? (string) $b) . '</span>';
                endforeach; ?>
                </div>
              <?php else: ?>
                <span class="table-empty">Belum ada</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php if($p['jumlah_transaksi']>0): ?>
                <span class="count-pill"><?=number_format((int) $p['jumlah_transaksi'], 0, ',', '.')?> transaksi</span>
              <?php else: ?>
                <span class="table-empty">0</span>
              <?php endif; ?>
            </td>
            <td class="money-cell <?=$p['total_nte']>0?'':'is-empty'?>">
              <?=$p['total_nte']>0?rupiah($p['total_nte']):'Rp 0'?>
            </td>
            <td class="money-cell target-cell">
              <?=$p['total_target']>0?rupiah($p['total_target']):'<span class="table-empty">Belum ada target</span>'?>
            </td>
            <td class="progress-cell">
              <?php if($pct!==null): ?>
                <div class="progress-stack">
                  <div class="progress-value" style="color:<?=$bar_color?>"><?=persen_tanpa_pembulatan($pct,2)?></div>
                  <div class="progress progress-thin">
                    <div class="progress-bar" style="width:<?=min($pct,100)?>%;background:<?=$bar_color?>"></div>
                  </div>
                </div>
              <?php else: ?>
                <span class="table-empty">Tidak dihitung</span>
              <?php endif; ?>
            </td>
          </tr>
          <tr class="penyuluh-detail-wrap p-0">
            <td colspan="10" class="p-0 border-top-0">
              <div class="collapse" id="<?=htmlspecialchars($collapse_id)?>">
                <div class="detail-panel">
                  <?php if (empty($detail_rows)): ?>
                    <p class="text-muted mb-0" style="font-size:12px">Tidak ada rincian per produk (belum ada realisasi maupun target terpisah per jenis produk).</p>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-sm mb-0 detail-product-table">
                        <thead>
                          <tr>
                            <th>Produk</th>
                            <th class="text-end" style="min-width:88px">Transaksi</th>
                            <th class="text-end">Realisasi NTE</th>
                            <th class="text-end">Target NTE</th>
                            <th style="min-width:110px">Capaian</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($detail_rows as $dr): 
                            $d_real = (float) $dr['total_nte'];
                            $d_tgt = (float) $dr['target_nte'];
                            $d_pct = $d_tgt > 0 ? ($d_real / $d_tgt * 100) : null;
                            $d_bar = $d_pct !== null ? ($d_pct >= 100 ? '#276749' : ($d_pct >= 50 ? 'var(--primary)' : '#e53e3e')) : '#cbd5e0';
                            $kat = trim((string) ($dr['nama_kategori'] ?? ''));
                          ?>
                          <tr>
                            <td>
                              <span class="fw-500"><?=htmlspecialchars($dr['nama_produk'])?></span>
                              <?php if ($kat !== ''): ?>
                                <div class="text-muted" style="font-size:10.5px"><?=htmlspecialchars($kat)?></div>
                              <?php endif; ?>
                            </td>
                            <td class="text-end"><?= (int) $dr['jumlah_transaksi'] > 0 ? number_format((int) $dr['jumlah_transaksi'], 0, ',', '.') . ' trx' : '<span class="table-empty">0</span>' ?></td>
                            <td class="text-end money-cell"><?= $d_real > 0 ? rupiah($d_real) : '<span class="table-empty">Rp 0</span>' ?></td>
                            <td class="text-end money-cell"><?= $d_tgt > 0 ? rupiah($d_tgt) : '<span class="table-empty">Belum ada target</span>' ?></td>
                            <td>
                              <?php if ($d_pct !== null): ?>
                                <div class="progress-stack compact">
                                  <div class="progress-value" style="color:<?=$d_bar?>"><?=persen_tanpa_pembulatan($d_pct, 2)?></div>
                                  <div class="progress progress-thin">
                                    <div class="progress-bar" style="width:<?=min($d_pct, 100)?>%;background:<?=$d_bar?>"></div>
                                  </div>
                                </div>
                              <?php else: ?>
                                <span class="table-empty">Tidak dihitung</span>
                              <?php endif; ?>
                            </td>
                          </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once 'footer.php'; ?>



