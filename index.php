<?php
require_once 'config.php';
$pageTitle = 'Dashboard';
$db = getDB();

$tahun_filter = isset($_GET['tahun']) ? (int) $_GET['tahun'] : (int) date('Y');

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

// === SUMMARY STATS ===
$total_nte = $db->query("SELECT COALESCE(SUM(nte_kth),0) as total FROM data_nte WHERE tahun=$tahun_filter")->fetch_assoc()['total'];
$total_target = $db->query("SELECT COALESCE(SUM(target_nte),0) as total FROM target_nte WHERE tahun=$tahun_filter")->fetch_assoc()['total'];
$total_transaksi = $db->query("SELECT COUNT(*) as c FROM data_nte WHERE tahun=$tahun_filter")->fetch_assoc()['c'];
$total_penyuluh = $db->query("SELECT COUNT(*) as c FROM penyuluh WHERE aktif=1")->fetch_assoc()['c'];

// Penyuluh sudah input = setiap bulan 1..bulan_akhir_monitor punya minimal satu baris NTE (tahun filter)
if ($bulan_akhir_monitor < 1) {
    $sudah_input = (int) $total_penyuluh;
    $belum_input = 0;
} else {
    $sudah_input = (int) $db->query("
      SELECT COUNT(*) AS c FROM penyuluh p
      WHERE p.aktif=1
        AND NOT EXISTS (
          SELECT 1 FROM $months_sql mo
          WHERE mo.bln <= $bulan_akhir_monitor
            AND NOT EXISTS (
              SELECT 1 FROM data_nte d
              WHERE d.penyuluh_id = p.id AND CAST(d.tahun AS UNSIGNED) = $tahun_filter
                AND CAST(d.bulan AS UNSIGNED) = mo.bln
            )
        )
    ")->fetch_assoc()['c'];
    $belum_input = (int) $total_penyuluh - $sudah_input;
}
$pct_realisasi = $total_target > 0 ? min(($total_nte / $total_target) * 100, 999) : 0;

// === NTE per bulan ===
$nte_bulan = [];
$res = $db->query("SELECT bulan, SUM(nte_kth) as total FROM data_nte WHERE tahun=$tahun_filter GROUP BY bulan ORDER BY bulan");
while($r = $res->fetch_assoc()) $nte_bulan[$r['bulan']] = $r['total'];

// === Top penyuluh ===
$top_penyuluh = $db->query("
  SELECT p.nama_penyuluh, k.nama_kabupaten, SUM(d.nte_kth) as total_nte,
         t.target_nte as target
  FROM data_nte d
  JOIN penyuluh p ON d.penyuluh_id = p.id
  JOIN kabupaten k ON d.kabupaten_id = k.id
  LEFT JOIN (SELECT penyuluh_id, SUM(target_nte) as target_nte FROM target_nte WHERE tahun=$tahun_filter GROUP BY penyuluh_id) t ON t.penyuluh_id = p.id
  WHERE d.tahun=$tahun_filter
  GROUP BY p.id, p.nama_penyuluh, k.nama_kabupaten, t.target_nte
  ORDER BY total_nte DESC
  LIMIT 10
");

// === NTE per kabupaten ===
$nte_kab = $db->query("SELECT k.nama_kabupaten, SUM(d.nte_kth) as total FROM data_nte d JOIN kabupaten k ON d.kabupaten_id=k.id WHERE d.tahun=$tahun_filter GROUP BY k.id ORDER BY total DESC");

// === NTE per kategori ===
$nte_kat = $db->query("SELECT kp.nama_kategori, SUM(d.nte_kth) as total FROM data_nte d JOIN kategori_produk kp ON d.kategori_id=kp.id WHERE d.tahun=$tahun_filter GROUP BY kp.id ORDER BY total DESC LIMIT 8");

// === Penyuluh belum input (ada bulan 1..bulan_akhir tanpa data sama sekali) ===
if ($bulan_akhir_monitor < 1) {
    $penyuluh_belum = $db->query("SELECT p.nama_penyuluh, k.nama_kabupaten, '' AS bulan_belum FROM penyuluh p JOIN kabupaten k ON p.kabupaten_id=k.id WHERE 1=0");
} else {
    $penyuluh_belum = $db->query("
      SELECT p.nama_penyuluh, k.nama_kabupaten, gap.bulan_belum
      FROM penyuluh p
      JOIN kabupaten k ON p.kabupaten_id=k.id
      JOIN (
        SELECT p2.id AS penyuluh_id, GROUP_CONCAT(mo.bln ORDER BY mo.bln) AS bulan_belum
        FROM penyuluh p2
        CROSS JOIN $months_sql mo
        WHERE p2.aktif=1
          AND mo.bln <= $bulan_akhir_monitor
          AND NOT EXISTS (
            SELECT 1 FROM data_nte d
            WHERE d.penyuluh_id = p2.id AND CAST(d.tahun AS UNSIGNED) = $tahun_filter
              AND CAST(d.bulan AS UNSIGNED) = mo.bln
          )
        GROUP BY p2.id
      ) gap ON gap.penyuluh_id = p.id
      WHERE p.aktif=1
      ORDER BY k.nama_kabupaten, p.nama_penyuluh
    ");
}

// Tahun tersedia
$tahun_list = $db->query("SELECT DISTINCT tahun FROM data_nte ORDER BY tahun DESC");

$bulan_labels = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des'];
$bulan_nama_id = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$dashboard_periode_teks = ($bulan_akhir_monitor >= 1)
    ? ('Jan-' . $bulan_labels[$bulan_akhir_monitor] . ' ' . $tahun_filter)
    : '';
$label_periode_monitor = $dashboard_periode_teks !== ''
    ? $dashboard_periode_teks
    : ('Tahun ' . $tahun_filter . ' (mendatang)');
$chart_bulan_labels = json_encode(array_values(array_filter($bulan_labels)));
$chart_bulan_data = json_encode(array_map(fn($i) => $nte_bulan[$i] ?? 0, range(1,12)));

$kab_rows = $nte_kab->fetch_all(MYSQLI_ASSOC);
$dashboard_colors = ['#1e6f5c','#29a882','#4dd0a5','#4299e1','#9f7aea','#ed8936','#fc8181','#f6ad55'];
$kab_total_chart = array_sum(array_map('floatval', array_column($kab_rows, 'total')));
$chart_kab_labels = json_encode(array_column($kab_rows, 'nama_kabupaten'));
$chart_kab_data = json_encode(array_column($kab_rows, 'total'));
$chart_colors = json_encode($dashboard_colors);

$kat_rows = $nte_kat->fetch_all(MYSQLI_ASSOC);
$chart_kat_labels = json_encode(array_column($kat_rows, 'nama_kategori'));
$chart_kat_data = json_encode(array_column($kat_rows, 'total'));

require_once 'header.php';



?>

<!-- Filter -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h5 class="mb-1 fw-700" style="color:#1a202c">Overview NTE KTH</h5>
    <p class="text-muted mb-0" style="font-size:13px">Monitoring realisasi vs target NTE KTH &mdash; <?= htmlspecialchars(APP_BRAND_ORG) ?></p>
  </div>
  <form method="GET" class="d-flex gap-2 align-items-center">
    <label class="form-label mb-0 me-1" style="font-size:13px;white-space:nowrap">Filter Tahun:</label>
    <select name="tahun" class="form-select form-select-sm" onchange="this.form.submit()" style="width:100px">
      <?php while($ty = $tahun_list->fetch_assoc()): ?>
        <option value="<?=$ty['tahun']?>" <?=($ty['tahun']==$tahun_filter)?'selected':''?>><?=$ty['tahun']?></option>
      <?php endwhile; ?>
    </select>
  </form>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div class="stat-icon" style="background:#e8f5f1"><i class="bi bi-currency-dollar" style="color:var(--primary)"></i></div>
        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700"><?=$tahun_filter?></span>
      </div>
      <div class="mt-2">
        <p class="text-xs text-gray-500 mb-0.5">Total Realisasi NTE</p>
        <p class="text-2xl font-bold text-gray-900 leading-tight tabular-nums mb-0"><?=rupiah($total_nte)?></p>
        <p class="text-xs text-gray-400 mt-0.5 mb-0">dari <?= $total_transaksi ?> transaksi</p>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div class="stat-icon" style="background:#fff5e6"><i class="bi bi-bullseye" style="color:#dd6b20"></i></div>
        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-700">Target</span>
      </div>
      <div class="mt-2">
        <p class="text-xs text-gray-500 mb-0.5">Total Target NTE</p>
        <p class="text-2xl font-bold text-gray-900 leading-tight tabular-nums mb-0"><?=rupiah($total_target)?></p>
        <div class="mt-3">
          <div class="flex items-center justify-between mb-1">
            <span class="text-xs text-gray-400">Capaian target</span>
            <span class="text-xs font-semibold <?=($pct_realisasi>=100)?'text-emerald-600':(($pct_realisasi<50)?'text-red-600':'text-orange-600')?>"><?=persen_tanpa_pembulatan($pct_realisasi,2)?></span>
          </div>
          <div class="w-full bg-gray-200 rounded-full h-1.5">
            <div class="h-1.5 rounded-full <?=($pct_realisasi>=100)?'bg-emerald-500':(($pct_realisasi<50)?'bg-red-500':'bg-orange-500')?>" style="width:<?=min($pct_realisasi,100)?>%"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div class="stat-icon" style="background:#ebf8ff"><i class="bi bi-person-check" style="color:#2b6cb0"></i></div>
        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">Per bulan</span>
      </div>
      <div class="stat-value"><?=$sudah_input?> <span style="font-size:14px;color:#718096">/ <?=$total_penyuluh?></span></div>
      <div class="stat-label">Penyuluh lengkap input</div>
      <div class="stat-change text-muted" style="font-size:11px;line-height:1.4">
        <?php if ($dashboard_periode_teks !== ''): ?>
          Sudah ada data NTE di <strong>setiap</strong> bulan <strong><?=htmlspecialchars($dashboard_periode_teks)?></strong>.
        <?php else: ?>
          Tahun lebih besar dari <?= (int) date('Y') ?> &mdash; pemantauan per bulan tidak berlaku.
        <?php endif; ?>
      </div>
      <div class="stat-change mt-2">
        <div class="progress" style="height:6px">
          <div class="progress-bar" style="background:#2b6cb0;width:<?=($total_penyuluh>0?($sudah_input/$total_penyuluh*100):0)?>%"></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div class="stat-icon" style="background:#fff5f5"><i class="bi bi-person-x" style="color:#c53030"></i></div>
        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">Per bulan</span>
      </div>
      <div class="stat-value" style="color:#c53030"><?=$belum_input?></div>
      <div class="stat-label">Penyuluh ada bulan kosong</div>
      <div class="stat-change text-muted" style="font-size:11px;line-height:1.4">
        <?php if ($dashboard_periode_teks !== ''): ?>
          Ada penyuluh yang belum mengisi <strong>satu atau lebih</strong> bulan pada <strong><?=htmlspecialchars($dashboard_periode_teks)?></strong>.
        <?php else: ?>
          Tahun mendatang &mdash; tidak ada daftar bulan yang diperiksa.
        <?php endif; ?>
      </div>
      <div class="stat-change mt-1">
        <a href="laporan_penyuluh.php?tahun=<?=$tahun_filter?>" class="text-decoration-none" style="font-size:12px;color:#c53030;font-weight:500">Lihat detail</a>
      </div>
    </div>
  </div>
</div>

<!-- Charts Row 1 -->
<div class="row g-3 mb-3">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6><i class="bi bi-graph-up me-2 text-success"></i>Tren NTE per Bulan (<?=$tahun_filter?>)</h6>
      </div>
      <div class="card-body"><canvas id="chartBulan" height="90"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">
        <h6><i class="bi bi-pie-chart me-2 text-success"></i>NTE per Kabupaten</h6>
      </div>
      <div class="card-body">
        <div class="flex items-center gap-4 mt-2">
          <div class="shrink-0" style="width:180px;height:180px">
            <canvas id="chartKab"></canvas>
          </div>
          <div class="flex-1 flex flex-col gap-1.5 overflow-y-auto min-w-0" style="max-height:180px">
            <?php foreach($kab_rows as $i => $kab):
              $kab_pct = $kab_total_chart > 0 ? ((float) $kab['total'] / $kab_total_chart * 100) : 0;
            ?>
            <div class="flex items-center gap-2 min-w-0" title="<?=h($kab['nama_kabupaten'])?>">
              <span class="w-2.5 h-2.5 rounded-sm shrink-0" style="background-color:<?=$dashboard_colors[$i % count($dashboard_colors)]?>"></span>
              <span class="text-xs text-gray-700 truncate flex-1"><?=h($kab['nama_kabupaten'])?></span>
              <span class="text-xs text-gray-400 shrink-0 tabular-nums"><?=number_format($kab_pct, 1, ',', '.')?>%</span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Charts Row 2 -->
<div class="row g-3 mb-3">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">
        <h6><i class="bi bi-bar-chart-horizontal me-2 text-success"></i>NTE per Kategori Produk</h6>
      </div>
      <div class="card-body"><canvas id="chartKat" height="160"></canvas></div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6><i class="bi bi-trophy me-2 text-success"></i>Top 10 Penyuluh - Realisasi vs Target</h6>
        <a href="laporan_penyuluh.php" class="btn btn-sm btn-outline-secondary" style="font-size:11px">Semua</a>
      </div>
      <div class="card-body p-0">
        <table class="table table-clean table-hover mb-0">
          <thead>
            <tr>
              <th class="ps-3">#</th>
              <th>Penyuluh</th>
              <th>Kabupaten</th>
              <th>Realisasi</th>
              <th>Target</th>
              <th>%</th>
            </tr>
          </thead>
          <tbody>
            <?php $i=1; while($row=$top_penyuluh->fetch_assoc()): 
              $pct = $row['target']>0 ? ($row['total_nte']/$row['target']*100) : 0;
            ?>
            <tr>
              <td class="ps-3 text-muted"><?=$i++?></td>
              <td style="max-width:150px"><div style="font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?=htmlspecialchars($row['nama_penyuluh'])?>"><?=htmlspecialchars($row['nama_penyuluh'])?></div></td>
              <td><span class="badge bg-light text-dark border" style="font-size:11px"><?=$row['nama_kabupaten']?></span></td>
              <td class="tabular-nums" style="font-size:12px"><?=rupiah($row['total_nte'])?></td>
              <td class="px-3 py-2.5 align-middle text-right whitespace-nowrap">
                <span class="text-sm text-gray-500 tabular-nums"><?=$row['target']>0?rupiah($row['target']):'-'?></span>
              </td>
              <td class="px-3 py-2.5 align-middle">
                <?php if($row['target']>0): ?>
                  <?php
                  $badge_class = match(true) {
                      $pct >= 100 => 'bg-emerald-100 text-emerald-700',
                      $pct >= 75 => 'bg-blue-100 text-blue-700',
                      $pct >= 50 => 'bg-yellow-100 text-yellow-700',
                      default => 'bg-red-100 text-red-700'
                  };
                  ?>
                  <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold <?=$badge_class?> tabular-nums"><?=persen_tanpa_pembulatan($pct,2)?></span>
                <?php else: ?><span class="text-gray-300">&mdash;</span><?php endif; ?>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Penyuluh Belum Input -->
<?php if($penyuluh_belum->num_rows > 0): ?>
<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6 class="mb-0"><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Daftar penyuluh &mdash; bulan tanpa data <span class="fw-normal text-muted" style="font-size:12px">(<?=htmlspecialchars($label_periode_monitor)?>)</span></h6>
    <span class="badge" style="background:#fed7d7;color:#9b2c2c"><?=$belum_input?> Penyuluh</span>
  </div>
  <div class="card-body">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mt-3">
      <?php while($pb=$penyuluh_belum->fetch_assoc()): ?>
      <div class="bg-white border border-red-200 rounded-xl p-3 hover:border-red-300 transition-colors">
        <div class="flex items-start gap-2 mb-2">
          <div class="w-7 h-7 rounded-full bg-red-100 flex items-center justify-center shrink-0">
            <svg class="w-3.5 h-3.5 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
          </div>
          <div class="min-w-0">
            <p class="text-sm font-semibold text-gray-800 leading-tight capitalize mb-0"><?=h($pb['nama_penyuluh'])?></p>
            <p class="text-xs text-gray-400 mb-0"><?=h($pb['nama_kabupaten'])?></p>
          </div>
        </div>
        <?php if (!empty($pb['bulan_belum'])): ?>
        <div class="flex flex-wrap gap-1">
          <span class="text-[10px] text-red-500 font-medium mb-0.5 w-full">Belum input:</span>
          <?php foreach (explode(',', $pb['bulan_belum']) as $bn): $bn = (int) $bn; ?>
          <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-red-100 text-red-700">
            <?=h($bulan_labels[$bn] ?? (string) $bn)?>
          </span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endwhile; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraJS = <<<JS
<script>
const fmtRupiah = (v) => {
  const val = Number(v) || 0;
  const factor = 100;
  const truncated = val >= 0 ? Math.floor(val * factor) / factor : Math.ceil(val * factor) / factor;
  return 'Rp ' + truncated.toLocaleString('id-ID', { maximumFractionDigits: 2 });
};
const tooltipRupiah = { callbacks: { label: ctx => fmtRupiah(ctx.raw) } };
const chartColors = {$chart_colors};
const fmtRupiahShort = (value) => {
  const val = Number(value) || 0;
  if (val >= 1e12) return 'Rp ' + (val / 1e12).toFixed(0) + ' T';
  if (val >= 1e9) return 'Rp ' + (val / 1e9).toFixed(0) + ' M';
  if (val >= 1e6) return 'Rp ' + (val / 1e6).toFixed(0) + ' jt';
  return 'Rp ' + val.toLocaleString('id-ID');
};

// Chart Bulan
new Chart(document.getElementById('chartBulan'), {
  type: 'bar',
  data: {
    labels: ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des'],
    datasets: [{
      label: 'Realisasi NTE',
      data: {$chart_bulan_data},
      backgroundColor: 'rgba(30,111,92,.15)',
      borderColor: 'rgba(30,111,92,.8)',
      borderWidth: 2, borderRadius: 6
    }]
  },
  options: {
    responsive: true, plugins: { legend: { display: false }, tooltip: tooltipRupiah },
    scales: { y: { ticks: { callback: v => fmtRupiah(v) }, grid: { color: '#f0f4f8' } }, x: { grid: { display: false } } }
  }
});

// Chart Kabupaten
new Chart(document.getElementById('chartKab'), {
  type: 'doughnut',
  data: {
    labels: {$chart_kab_labels},
    datasets: [{ data: {$chart_kab_data}, backgroundColor: chartColors, borderWidth: 2 }]
  },
  options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: tooltipRupiah } }
});

// Chart Kategori
new Chart(document.getElementById('chartKat'), {
  type: 'bar',
  data: {
    labels: {$chart_kat_labels},
    datasets: [{ label: 'NTE', data: {$chart_kat_data}, backgroundColor: 'rgba(41,168,130,.2)', borderColor: '#29a882', borderWidth: 2, borderRadius: 4 }]
  },
  options: {
    indexAxis: 'y', responsive: true,
    plugins: { legend: { display: false }, tooltip: tooltipRupiah },
    scales: {
      x: {
        ticks: {
          callback: function(value) {
            return fmtRupiahShort(value);
          },
          maxRotation: 0,
          minRotation: 0,
          font: { size: 10 }
        }
      },
      y: {
        ticks: { font: { size: 11 } }
      }
    }
  }
});
</script>
JS;
require_once 'footer.php';
?>


