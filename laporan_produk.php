<?php
require_once 'config.php';
$pageTitle = 'Realisasi per Produk';
$db = getDB();

$tahun_filter = isset($_GET['tahun']) ? (int) $_GET['tahun'] : (int) date('Y');
$kab_filter = isset($_GET['kab_id']) ? (int)$_GET['kab_id'] : 0;
$kat_filter = isset($_GET['kat_id']) ? (int)$_GET['kat_id'] : 0;

$kabupaten_list = $db->query("SELECT id, nama_kabupaten FROM kabupaten ORDER BY nama_kabupaten");
$tahun_list = $db->query("SELECT DISTINCT tahun FROM data_nte ORDER BY tahun DESC");
$kat_list = $db->query("SELECT id, nama_kategori FROM kategori_produk ORDER BY nama_kategori");

$kab_cond = $kab_filter ? "AND d.kabupaten_id=$kab_filter" : "";
$kat_cond = $kat_filter ? "AND kp.id=$kat_filter" : "";
$target_kab_join = $kab_filter ? "JOIN penyuluh pt ON pt.id=t.penyuluh_id AND pt.kabupaten_id=$kab_filter" : "";

// NTE per produk + target
$produk_data = $db->query("
  SELECT jp.id, jp.nama_produk, kp.nama_kategori,
         SUM(d.nte_kth) as total_nte,
         COUNT(*) as jumlah_transaksi,
         SUM(d.jumlah_penjualan) as total_jumlah,
         COUNT(DISTINCT d.penyuluh_id) as jumlah_penyuluh,
         COALESCE(t.total_target,0) + COALESCE(tk.total_target_kategori,0) as total_target
  FROM data_nte d
  JOIN jenis_produk jp ON d.jenis_produk_id=jp.id
  LEFT JOIN kategori_produk kp ON jp.kategori_id=kp.id
  LEFT JOIN (
    SELECT t.jenis_produk_id, SUM(t.target_nte) as total_target
    FROM target_nte t
    $target_kab_join
    WHERE t.tahun=$tahun_filter
    GROUP BY t.jenis_produk_id
  ) t ON t.jenis_produk_id=jp.id
  LEFT JOIN (
    SELECT jpt.kategori_id, SUM(t.target_nte) as total_target_kategori
    FROM target_nte t
    JOIN jenis_produk jpt ON t.jenis_produk_id=jpt.id
    LEFT JOIN kategori_produk kpt ON jpt.kategori_id=kpt.id
    $target_kab_join
    WHERE t.tahun=$tahun_filter
      AND jpt.nama_produk <=> kpt.nama_kategori
    GROUP BY jpt.kategori_id
  ) tk ON tk.kategori_id=jp.kategori_id
  WHERE d.tahun=$tahun_filter $kab_cond $kat_cond
  GROUP BY jp.id, jp.nama_produk, kp.nama_kategori, t.total_target, tk.total_target_kategori
  ORDER BY total_nte DESC
");

// NTE per kategori untuk chart
$kat_data = $db->query("
  SELECT kp.nama_kategori, SUM(d.nte_kth) as total
  FROM data_nte d
  JOIN kategori_produk kp ON d.kategori_id=kp.id
  WHERE d.tahun=$tahun_filter $kab_cond
  GROUP BY kp.id ORDER BY total DESC
");

// NTE per bulan per kategori
$bulan_kat = $db->query("
  SELECT kp.nama_kategori, d.bulan, SUM(d.nte_kth) as total
  FROM data_nte d
  JOIN kategori_produk kp ON d.kategori_id=kp.id
  WHERE d.tahun=$tahun_filter $kab_cond
  GROUP BY kp.id, kp.nama_kategori, d.bulan
  ORDER BY kp.nama_kategori, d.bulan
");

$kat_rows = $kat_data->fetch_all(MYSQLI_ASSOC);
$chart_kat_labels = json_encode(array_column($kat_rows, 'nama_kategori'));
$chart_kat_data = json_encode(array_column($kat_rows, 'total'));

// Prepare bulan-kat data
$bulan_kat_data = [];
$all_kats = [];
while ($r = $bulan_kat->fetch_assoc()) {
    $bulan_kat_data[$r['nama_kategori']][$r['bulan']] = $r['total'];
    $all_kats[$r['nama_kategori']] = true;
}
$all_kats = array_keys($all_kats);

$colors = ['#1e6f5c','#29a882','#38b2ac','#4299e1','#9f7aea','#ed8936','#fc8181','#f6ad55'];
$chart_datasets = [];
foreach ($all_kats as $ci => $kat) {
    $data = array_map(fn($m) => $bulan_kat_data[$kat][$m] ?? 0, range(1,12));
    $totalNilai = array_sum(array_map('floatval', $data));
    if ($totalNilai > 0) {
        $chart_datasets[] = ['label'=>$kat,'data'=>$data,'borderColor'=>$colors[$ci%count($colors)],'backgroundColor'=>$colors[$ci%count($colors)].'22','tension'=>.3,'fill'=>false,'pointRadius'=>3];
    }
}
$chart_datasets = array_values(array_filter($chart_datasets, fn($dataset) => array_sum(array_map('floatval', $dataset['data'])) > 0));
usort($chart_datasets, function($a, $b) {
    $totalA = array_sum(array_map('floatval', $a['data']));
    $totalB = array_sum(array_map('floatval', $b['data']));
    return $totalB <=> $totalA;
});
$chart_datasets = array_values($chart_datasets);

foreach ($chart_datasets as $ci => &$ds) {
    if ($ci < 5) {
        $ds['borderWidth'] = 2.5;
        $ds['borderDash'] = [];
        $ds['pointRadius'] = 3;
    } else {
        $baseColor = $ds['borderColor'];
        $ds['borderWidth'] = 1;
        $ds['borderDash'] = [4, 4];
        $ds['pointRadius'] = 0;
        $ds['borderColor'] = $baseColor . '40';
        $ds['backgroundColor'] = $baseColor . '00';
    }
}
unset($ds);
$chart_line_data = json_encode(['labels'=>['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des'],'datasets'=>$chart_datasets]);

$produk_rows = $produk_data->fetch_all(MYSQLI_ASSOC);
$grand_total = array_sum(array_column($produk_rows, 'total_nte'));

require_once 'header.php';



?>

<script>
window.tailwind = window.tailwind || {};
window.tailwind.config = { corePlugins: { preflight: false } };
</script>
<script src="https://cdn.tailwindcss.com"></script>

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
        <label class="form-label mb-1" style="font-size:12px">Kategori</label>
        <select name="kat_id" class="form-select form-select-sm">
          <option value="">Semua Kategori</option>
          <?php $kat_list->data_seek(0); while($kt=$kat_list->fetch_assoc()): ?>
          <option value="<?=$kt['id']?>" <?=$kt['id']==$kat_filter?'selected':''?>><?=htmlspecialchars($kt['nama_kategori'])?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Filter</button>
      </div>
      <div class="col-md-2">
        <a href="laporan_produk.php?tahun=<?=$tahun_filter?>" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6><i class="bi bi-pie-chart me-2 text-success"></i>Proporsi per Kategori</h6></div>
      <div class="card-body">
        <div class="flex items-center gap-4">
          <div class="shrink-0" style="width:210px;height:210px">
            <canvas id="chartKat"></canvas>
          </div>
          <div class="flex-1 overflow-y-auto flex flex-col gap-1 min-w-0 pr-2 [&::-webkit-scrollbar]:w-1 [&::-webkit-scrollbar-track]:bg-transparent [&::-webkit-scrollbar-thumb]:bg-gray-300 [&::-webkit-scrollbar-thumb]:rounded-full hover:[&::-webkit-scrollbar-thumb]:bg-gray-400" style="min-width:160px;max-height:210px">
            <?php
            $kat_total_chart = array_sum(array_map('floatval', array_column($kat_rows, 'total')));
            foreach ($kat_rows as $i => $kr):
              $kv = (float) $kr['total'];
              if ($kv <= 0) continue;
              $kpct = $kat_total_chart > 0 ? ($kv / $kat_total_chart * 100) : 0;
              $kcolor = $colors[$i % count($colors)];
            ?>
            <div class="flex items-center gap-2 min-w-0 cursor-default" title="<?=h($kr['nama_kategori'])?>">
              <span class="w-2.5 h-2.5 rounded-sm shrink-0" style="background-color:<?=$kcolor?>"></span>
              <span class="text-[11px] text-gray-700 truncate flex-1" style="max-width:140px"><?=h($kr['nama_kategori'])?></span>
              <span class="text-xs text-gray-400 shrink-0 tabular-nums ml-auto"><?=number_format($kpct, 1, ',', '.')?>%</span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6><i class="bi bi-graph-up me-2 text-success"></i>Tren NTE per Kategori - <?=$tahun_filter?></h6></div>
      <div class="card-body">
        <canvas id="chartLine" height="80"></canvas>
        <p class="text-[11px] text-gray-400 text-right mt-1 px-2">
          Menampilkan 5 kategori teratas. Kategori lain ditampilkan sebagai garis samar.
        </p>
      </div>
    </div>
  </div>
</div>

<!-- Produk Table -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
  <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
    <h2 class="text-sm font-semibold text-gray-800 mb-0"><i class="bi bi-table me-2 text-success"></i>Detail Realisasi per Jenis Produk</h2>
    <span class="text-sm font-semibold text-emerald-700">Total: <?=rupiah($grand_total)?></span>
  </div>
  <div>
    <div class="w-full overflow-x-auto">
      <table class="w-full border-collapse">
        <thead>
          <tr class="bg-gray-50 border-b-2 border-gray-200">
            <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide w-8">#</th>
            <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide" style="min-width:180px">Jenis Produk</th>
            <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide" style="min-width:150px">Kategori</th>
            <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide w-24">Aktivitas</th>
            <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap" style="min-width:130px">Total NTE (Rp)</th>
            <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap" style="min-width:160px">Target / Capaian</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach($produk_rows as $i=>$pr): 
            $pct = $pr['total_target']>0 ? ($pr['total_nte']/$pr['total_target']*100) : null;
            $pct_total = $grand_total>0 ? ($pr['total_nte']/$grand_total*100) : 0;
            $pct_width = $pct === null ? 0 : ($pct > 100 ? 100 : ($pct > 0 && $pct < 2 ? 2 : $pct));
            $pct_bar = $pct === null ? 'bg-gray-300' : ($pct >= 100 ? 'bg-emerald-500' : ($pct >= 50 ? 'bg-yellow-500' : 'bg-red-500'));
            $pct_text = $pct === null ? 'text-gray-300' : ($pct >= 100 ? 'text-emerald-700' : ($pct >= 50 ? 'text-yellow-600' : 'text-red-600'));
            $prop_width = $pct_total > 100 ? 100 : ($pct_total > 0 && $pct_total < 2 ? 2 : $pct_total);
          ?>
          <tr class="border-b border-gray-100 <?=$i % 2 === 1 ? 'bg-gray-50/50' : 'bg-white'?> hover:bg-emerald-50/30 transition-colors">
            <td class="px-3 py-2.5 align-middle w-8 text-sm text-gray-400 tabular-nums"><?=$i+1?></td>
            <td class="px-3 py-2.5 align-middle" style="min-width:180px">
              <span class="text-sm font-semibold text-gray-800 capitalize leading-snug block" title="<?=h($pr['nama_produk'])?>">
                <?=h($pr['nama_produk'])?>
              </span>
            </td>
            <td class="px-3 py-2.5 align-middle">
              <?php if (!empty($pr['nama_kategori'])): ?>
                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-600 border border-gray-200 whitespace-nowrap"><?=h($pr['nama_kategori'])?></span>
              <?php else: ?>
                <span class="text-sm text-gray-300">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="px-3 py-2.5 align-middle text-center">
              <div class="flex flex-col items-center gap-0.5">
                <div class="flex items-center gap-1">
                  <svg class="w-3 h-3 text-gray-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7z"></path>
                  </svg>
                  <span class="text-sm font-semibold text-gray-700 tabular-nums"><?=$pr['jumlah_penyuluh']?></span>
                </div>
                <div class="flex items-center gap-1">
                  <svg class="w-3 h-3 text-gray-300 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2"></path>
                  </svg>
                  <span class="text-xs text-gray-400 tabular-nums"><?=$pr['jumlah_transaksi']?></span>
                </div>
              </div>
            </td>
            <td class="px-3 py-2.5 align-middle text-right whitespace-nowrap">
              <div class="flex flex-col items-end gap-0">
                <span class="text-sm font-bold text-gray-900 tabular-nums"><?=number_format((float) $pr['total_nte'], 0, ',', '.')?></span>
                <span class="text-xs text-gray-400 tabular-nums"><?=persen_tanpa_pembulatan($pct_total,2)?></span>
              </div>
            </td>
            <td class="px-3 py-2.5 align-middle" style="min-width:160px">
              <?php if($pct!==null): ?>
                <div class="flex flex-col gap-1.5">
                  <div class="flex items-center justify-between gap-3 mb-1">
                    <span class="text-xs text-gray-400 shrink-0">Target</span>
                    <span class="text-xs text-gray-500 whitespace-nowrap tabular-nums"><?=number_format((float) $pr['total_target'], 0, ',', '.')?></span>
                  </div>
                  <div class="flex items-center gap-2">
                    <div class="flex-1 bg-gray-200 rounded-full h-2">
                      <div class="h-2 rounded-full transition-all" style="width:<?=$pct_width?>%;background-color:<?=$pct >= 100 ? '#10b981' : ($pct >= 50 ? '#f59e0b' : '#ef4444')?>"></div>
                    </div>
                    <span class="text-xs font-semibold whitespace-nowrap shrink-0 tabular-nums" style="color:<?=$pct >= 100 ? '#059669' : ($pct >= 50 ? '#d97706' : '#dc2626')?>"><?=persen_tanpa_pembulatan($pct,2)?></span>
                  </div>
                </div>
              <?php else: ?>
                <span class="text-sm text-gray-300">&mdash;</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
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

new Chart(document.getElementById('chartKat'), {
  type: 'doughnut',
  data: { labels: {$chart_kat_labels}, datasets: [{ data: {$chart_kat_data}, backgroundColor: colors, borderWidth: 2 }] },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '65%',
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: function(context) {
            const value = Number(context.parsed) || 0;
            const total = context.dataset.data.reduce((a, b) => Number(a) + Number(b || 0), 0);
            const pct = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
            return ' ' + context.label + ': ' + fmtRupiah(value) + ' (' + pct + '%)';
          }
        }
      }
    }
  }
});

new Chart(document.getElementById('chartLine'), {
  type: 'line',
  data: {$chart_line_data},
  options: {
    responsive: true, interaction: { mode:'index' },
    plugins: {
      legend: {
        display: true,
        position: 'bottom',
        labels: {
          filter: function(legendItem) {
            return legendItem.datasetIndex < 5;
          },
          boxWidth: 10,
          boxHeight: 10,
          padding: 8,
          font: { size: 10 }
        }
      },
      tooltip: {
        ...tooltipRupiah,
        filter: function(tooltipItem) {
          return Number(tooltipItem.raw) > 0;
        }
      }
    },
    scales: { y: { ticks: { callback: v => fmtRupiah(v) } }, x: { grid: { display:false } } }
  }
});
</script>
JS;
require_once 'footer.php';
?>


