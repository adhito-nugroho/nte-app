<?php
require_once 'config.php';
$pageTitle = 'Realisasi per Penyuluh';
$db = getDB();

$tahun_filter = isset($_GET['tahun']) ? (int) $_GET['tahun'] : (int) date('Y');
$kab_filter = isset($_GET['kab_id']) ? (int) $_GET['kab_id'] : 0;

$kabupaten_list = $db->query("SELECT id, nama_kabupaten FROM kabupaten ORDER BY nama_kabupaten");
$tahun_list = $db->query("SELECT DISTINCT tahun FROM data_nte ORDER BY tahun DESC");
$tahun_rows = $tahun_list ? $tahun_list->fetch_all(MYSQLI_ASSOC) : [];
if (!$tahun_rows) {
    $tahun_rows = [['tahun' => $tahun_filter]];
}

$kab_where_p = $kab_filter ? "AND p.kabupaten_id=$kab_filter" : "";
$kab_where_d = $kab_filter ? "AND d.kabupaten_id=$kab_filter" : "";

$penyuluh_data = $db->query("
  SELECT p.id, p.nama_penyuluh, k.nama_kabupaten,
         COUNT(d.id) AS jumlah_transaksi,
         COALESCE(SUM(d.nte_kth), 0) AS total_nte,
         COALESCE(SUM(d.jumlah_penjualan), 0) AS total_jumlah,
         COUNT(DISTINCT d.jenis_produk_id) AS jumlah_produk,
         COALESCE(t.total_target, 0) AS total_target
  FROM penyuluh p
  JOIN kabupaten k ON p.kabupaten_id = k.id
  LEFT JOIN data_nte d
    ON d.penyuluh_id = p.id
   AND CAST(d.tahun AS UNSIGNED) = $tahun_filter
  LEFT JOIN (
    SELECT penyuluh_id, SUM(target_nte) AS total_target
    FROM target_nte
    WHERE tahun = $tahun_filter
    GROUP BY penyuluh_id
  ) t ON t.penyuluh_id = p.id
  WHERE p.aktif = 1 $kab_where_p
  GROUP BY p.id, p.nama_penyuluh, k.nama_kabupaten, t.total_target
  ORDER BY total_nte DESC, jumlah_transaksi DESC, p.nama_penyuluh
");

$bulan_data = $db->query("
  SELECT d.bulan, SUM(d.nte_kth) AS total
  FROM data_nte d
  WHERE CAST(d.tahun AS UNSIGNED) = $tahun_filter $kab_where_d
  GROUP BY d.bulan
  ORDER BY d.bulan
");

$kab_data = $db->query("
  SELECT k.nama_kabupaten, SUM(d.nte_kth) AS total
  FROM data_nte d
  JOIN kabupaten k ON d.kabupaten_id = k.id
  WHERE CAST(d.tahun AS UNSIGNED) = $tahun_filter $kab_where_d
  GROUP BY k.id, k.nama_kabupaten
  ORDER BY total DESC
");

$penyuluh_rows = $penyuluh_data ? $penyuluh_data->fetch_all(MYSQLI_ASSOC) : [];
$grand_total = array_sum(array_column($penyuluh_rows, 'total_nte'));
$total_target = array_sum(array_column($penyuluh_rows, 'total_target'));
$total_transaksi = array_sum(array_column($penyuluh_rows, 'jumlah_transaksi'));
$penyuluh_aktif_data = count(array_filter($penyuluh_rows, fn($row) => (float) $row['total_nte'] > 0));
$total_penyuluh = count($penyuluh_rows);
$pct_target = $total_target > 0 ? ($grand_total / $total_target * 100) : null;

$bulan_totals = array_fill(1, 12, 0);
if ($bulan_data) {
    while ($r = $bulan_data->fetch_assoc()) {
        $bulan = (int) $r['bulan'];
        if ($bulan >= 1 && $bulan <= 12) {
            $bulan_totals[$bulan] = (float) $r['total'];
        }
    }
}

$kab_rows = $kab_data ? $kab_data->fetch_all(MYSQLI_ASSOC) : [];
$chart_bulan_data = json_encode(array_values($bulan_totals));
$chart_kab_labels = json_encode(array_column($kab_rows, 'nama_kabupaten'));
$chart_kab_data = json_encode(array_column($kab_rows, 'total'));
$top10 = array_slice($penyuluh_rows, 0, 10);
$chart_top_labels = json_encode(array_column($top10, 'nama_penyuluh'));
$chart_top_data = json_encode(array_column($top10, 'total_nte'));

require_once 'header.php';
?>

<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end" data-disable-on-submit>
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:12px">Tahun</label>
        <select name="tahun" class="form-select form-select-sm">
          <?php foreach($tahun_rows as $ty): ?>
          <option value="<?=$ty['tahun']?>" <?=$ty['tahun']==$tahun_filter?'selected':''?>><?=$ty['tahun']?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label mb-1" style="font-size:12px">Kabupaten</label>
        <select name="kab_id" class="form-select form-select-sm">
          <option value="">Semua Kabupaten</option>
          <?php while($k=$kabupaten_list->fetch_assoc()): ?>
          <option value="<?=$k['id']?>" <?=$k['id']==$kab_filter?'selected':''?>><?=h($k['nama_kabupaten'])?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Filter</button>
      </div>
      <div class="col-md-2">
        <a href="laporan_realisasi_penyuluh.php?tahun=<?=$tahun_filter?>" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-value stat-value-money"><?=rupiah($grand_total)?></div>
      <div class="stat-label">Total Realisasi NTE</div>
      <div class="stat-change text-muted"><?=$total_transaksi?> transaksi</div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-value"><?=$penyuluh_aktif_data?> <span style="font-size:14px;color:#718096">/ <?=$total_penyuluh?></span></div>
      <div class="stat-label">Penyuluh dengan realisasi</div>
      <div class="stat-change text-muted">Aktif pada filter berjalan</div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-value stat-value-money"><?=rupiah($total_target)?></div>
      <div class="stat-label">Total Target NTE</div>
      <div class="stat-change text-muted"><?=$pct_target === null ? 'Belum ada target' : 'Capaian ' . persen_tanpa_pembulatan($pct_target, 2)?></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-value stat-value-money"><?=rupiah($total_penyuluh > 0 ? $grand_total / $total_penyuluh : 0)?></div>
      <div class="stat-label">Rata-rata per penyuluh</div>
      <div class="stat-change text-muted">Berdasarkan penyuluh aktif</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6><i class="bi bi-pie-chart me-2 text-success"></i>Proporsi per Kabupaten</h6></div>
      <div class="card-body"><canvas id="chartKab"></canvas></div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6><i class="bi bi-graph-up me-2 text-success"></i>Tren NTE per Bulan - <?=$tahun_filter?></h6></div>
      <div class="card-body"><canvas id="chartBulan" height="80"></canvas></div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6><i class="bi bi-bar-chart-line me-2 text-success"></i>Top 10 Penyuluh</h6>
    <span style="font-size:12px;color:#718096">Total: <strong><?=rupiah($grand_total)?></strong></span>
  </div>
  <div class="card-body"><canvas id="chartTop" height="90"></canvas></div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h6><i class="bi bi-person-lines-fill me-2 text-success"></i>Tabel Realisasi per Penyuluh</h6>
      <div class="text-muted mt-1" style="font-size:11px">Baris hijau menandai 5 realisasi tertinggi; baris oranye menandai 5 realisasi terendah.</div>
    </div>
    <span class="badge bg-light text-dark border"><?=$total_penyuluh?> penyuluh</span>
  </div>
  <div class="card-body p-0">
    <?php if (!$penyuluh_rows): ?>
      <div class="empty-state">
        <i class="bi bi-person-lines-fill"></i>
        <div class="empty-state-title">Belum ada data penyuluh</div>
        <p class="empty-state-text">Import data Excel atau tambahkan master penyuluh terlebih dahulu.</p>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm ranking-table mb-0">
        <thead>
          <tr>
            <th class="ps-3">Penyuluh</th>
            <th>Kabupaten</th>
            <th class="text-end">Transaksi</th>
            <th class="text-end">Realisasi NTE</th>
            <th class="text-end">Target</th>
            <th class="text-end">Capaian</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $rowCount = count($penyuluh_rows);
          foreach($penyuluh_rows as $i => $row):
            $rank = $i + 1;
            $isTop = $rank <= min(5, $rowCount);
            $isBottom = $rowCount > 5 && $rank > max(5, $rowCount - 5);
            $rowClass = $isTop ? 'rank-top' : ($isBottom ? 'rank-bottom' : 'rank-neutral');
            $target = (float) $row['total_target'];
            $total = (float) $row['total_nte'];
            $pct = $target > 0 ? ($total / $target * 100) : null;
          ?>
          <tr class="<?=$rowClass?>">
            <td class="ps-3 name-cell"><?=h($row['nama_penyuluh'])?></td>
            <td class="kab-cell"><?=h($row['nama_kabupaten'])?></td>
            <td class="num-cell"><?=number_format((int) $row['jumlah_transaksi'], 0, ',', '.')?></td>
            <td class="money-cell"><?=rupiah($total)?></td>
            <td class="money-cell"><?=$target > 0 ? rupiah($target) : '<span class="text-muted">-</span>'?></td>
            <td class="num-cell"><?=$pct === null ? '<span class="text-muted">-</span>' : persen_tanpa_pembulatan($pct, 2)?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
$extraJS = <<<JS
<script>
const fmtRupiah = v => {
  const val = Number(v) || 0;
  const factor = 100;
  const truncated = val >= 0 ? Math.floor(val * factor) / factor : Math.ceil(val * factor) / factor;
  return 'Rp ' + truncated.toLocaleString('id-ID', { maximumFractionDigits: 2 });
};
const tooltipRupiah = { callbacks: { label: ctx => fmtRupiah(ctx.raw) } };
const colors = ['#1e6f5c','#29a882','#38b2ac','#4299e1','#9f7aea','#ed8936','#fc8181','#f6ad55'];

new Chart(document.getElementById('chartKab'), {
  type: 'doughnut',
  data: { labels: {$chart_kab_labels}, datasets: [{ data: {$chart_kab_data}, backgroundColor: colors, borderWidth: 2 }] },
  options: { responsive: true, plugins: { legend: { position:'bottom', labels:{font:{size:11}} }, tooltip: tooltipRupiah } }
});

new Chart(document.getElementById('chartBulan'), {
  type: 'bar',
  data: {
    labels: ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des'],
    datasets: [{ label: 'Realisasi NTE', data: {$chart_bulan_data}, backgroundColor: 'rgba(30,111,92,.18)', borderColor: '#1e6f5c', borderWidth: 2, borderRadius: 5 }]
  },
  options: { responsive: true, plugins: { legend: { display:false }, tooltip: tooltipRupiah }, scales: { y: { ticks: { callback: v => fmtRupiah(v) } }, x: { grid: { display:false } } } }
});

new Chart(document.getElementById('chartTop'), {
  type: 'bar',
  data: { labels: {$chart_top_labels}, datasets: [{ label: 'Realisasi NTE', data: {$chart_top_data}, backgroundColor: 'rgba(41,168,130,.2)', borderColor: '#29a882', borderWidth: 2, borderRadius: 4 }] },
  options: {
    indexAxis: 'y',
    responsive: true,
    plugins: { legend: { display:false }, tooltip: tooltipRupiah },
    scales: { x: { ticks: { callback: v => fmtRupiah(v) } }, y: { ticks: { font: { size: 11 } } } }
  }
});
</script>
JS;
require_once 'footer.php';
?>
