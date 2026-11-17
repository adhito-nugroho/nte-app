<?php
require_once 'config.php';
$pageTitle = 'Detail Transaksi';
$db = getDB();

$kab_filter = isset($_GET['kab_id']) ? (int)$_GET['kab_id'] : 0;
$penyuluh_filter = isset($_GET['penyuluh_id']) ? (int)$_GET['penyuluh_id'] : 0;
$produk_filter = isset($_GET['produk_id']) ? (int)$_GET['produk_id'] : 0;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 25;
$offset = ($page - 1) * $perPage;


$kabupaten_list = $db->query("SELECT id, nama_kabupaten FROM kabupaten ORDER BY nama_kabupaten");
$penyuluh_list = $db->query("SELECT id, nama_penyuluh FROM penyuluh WHERE aktif=1 ORDER BY nama_penyuluh");
$produk_list = $db->query("SELECT id, nama_produk FROM jenis_produk ORDER BY nama_produk");

$where = "WHERE 1=1";
if ($kab_filter) $where .= " AND d.kabupaten_id=$kab_filter";
if ($penyuluh_filter) $where .= " AND d.penyuluh_id=$penyuluh_filter";
if ($produk_filter) $where .= " AND d.jenis_produk_id=$produk_filter";

$agg = $db->query("
    SELECT
        COUNT(*) as total_transaksi,
        COALESCE(SUM(d.nte_kth), 0) as total_nte,
        COALESCE(SUM(d.jumlah_penjualan), 0) as total_penjualan,
        COUNT(DISTINCT d.kabupaten_id) as total_kabupaten,
        COUNT(DISTINCT d.penyuluh_id) as total_penyuluh,
        COUNT(DISTINCT d.jenis_produk_id) as total_produk
    FROM data_nte d
    $where
")->fetch_assoc();

$totalRows = (int)$agg['total_transaksi'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$transaksi_rows = $db->query("
    SELECT
        d.id, d.tahun, d.bulan, d.nama_kelompok, d.jumlah_penjualan, d.satuan, d.nte_kth,
        p.nama_penyuluh, k.nama_kabupaten, jp.nama_produk, kp.nama_kategori
    FROM data_nte d
    LEFT JOIN penyuluh p ON d.penyuluh_id = p.id
    LEFT JOIN kabupaten k ON d.kabupaten_id = k.id
    LEFT JOIN jenis_produk jp ON d.jenis_produk_id = jp.id
    LEFT JOIN kategori_produk kp ON d.kategori_id = kp.id
    $where
    ORDER BY d.tahun DESC, d.bulan DESC, d.id DESC
    LIMIT $perPage OFFSET $offset
");

$queryBase = $_GET;
unset($queryBase['page']);
function page_url($targetPage, $queryBase) {
    $query = $queryBase;
    $query['page'] = $targetPage;
    return 'transaksi.php?' . http_build_query($query);
}

$bulan_names = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des'];

require_once 'header.php';
?>
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:12px">Kabupaten</label>
        <select name="kab_id" class="form-select form-select-sm">
          <option value="">Semua Kabupaten</option>
          <?php while($k = $kabupaten_list->fetch_assoc()): ?>
            <option value="<?=$k['id']?>" <?=$k['id']==$kab_filter?'selected':''?>><?=htmlspecialchars($k['nama_kabupaten'])?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:12px">Penyuluh</label>
        <select name="penyuluh_id" class="form-select form-select-sm">
          <option value="">Semua Penyuluh</option>
          <?php while($p = $penyuluh_list->fetch_assoc()): ?>
            <option value="<?=$p['id']?>" <?=$p['id']==$penyuluh_filter?'selected':''?>><?=htmlspecialchars($p['nama_penyuluh'])?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:12px">Produk</label>
        <select name="produk_id" class="form-select form-select-sm">
          <option value="">Semua Produk</option>
          <?php while($pr = $produk_list->fetch_assoc()): ?>
            <option value="<?=$pr['id']?>" <?=$pr['id']==$produk_filter?'selected':''?>><?=htmlspecialchars($pr['nama_produk'])?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Filter</button>
      </div>
      <div class="col-md-1">
        <a href="transaksi.php" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="row g-3 mb-2">
  <div class="col-md-6 col-xl-4">
    <div class="agg-card">
      <div class="agg-head">
        <p class="agg-title">Total Transaksi</p>
        <span class="agg-icon"><i class="bi bi-receipt"></i></span>
      </div>
      <p class="agg-value"><?=$agg['total_transaksi']?></p>
      <div class="agg-note">Jumlah baris transaksi sesuai filter</div>
    </div>
  </div>
  <div class="col-md-6 col-xl-4">
    <div class="agg-card">
      <div class="agg-head">
        <p class="agg-title">Total Nilai Transaksi</p>
        <span class="agg-icon"><i class="bi bi-currency-dollar"></i></span>
      </div>
      <p class="agg-value currency">Rp <?=format_tanpa_pembulatan((float)$agg['total_nte'], 2)?></p>
      <div class="agg-note">Akumulasi nilai transaksi (NTE)</div>
    </div>
  </div>
  <div class="col-md-6 col-xl-4">
    <div class="agg-card">
      <div class="agg-head">
        <p class="agg-title">Total Penjualan</p>
        <span class="agg-icon"><i class="bi bi-box-seam"></i></span>
      </div>
      <p class="agg-value"><?=format_tanpa_pembulatan((float)$agg['total_penjualan'], 2)?></p>
      <div class="agg-note">Akumulasi jumlah penjualan</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="agg-card">
      <div class="agg-head">
        <p class="agg-title">Kabupaten</p>
        <span class="agg-icon"><i class="bi bi-geo-alt"></i></span>
      </div>
      <p class="agg-value"><?=$agg['total_kabupaten']?></p>
      <div class="agg-note">Kabupaten unik pada data</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="agg-card">
      <div class="agg-head">
        <p class="agg-title">Penyuluh</p>
        <span class="agg-icon"><i class="bi bi-person-badge"></i></span>
      </div>
      <p class="agg-value"><?=$agg['total_penyuluh']?></p>
      <div class="agg-note">Penyuluh unik pada data</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="agg-card">
      <div class="agg-head">
        <p class="agg-title">Produk</p>
        <span class="agg-icon"><i class="bi bi-tags"></i></span>
      </div>
      <p class="agg-value"><?=$agg['total_produk']?></p>
      <div class="agg-note">Produk unik pada data</div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6><i class="bi bi-table me-2 text-success"></i>Data Transaksi</h6>
    <span class="badge bg-light text-dark border">Halaman <?=$page?> dari <?=$totalPages?></span>
  </div>
  <div class="card-body p-0">
    <?php if ($transaksi_rows->num_rows == 0): ?>
      <div class="text-center py-5 text-muted">
        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
        Tidak ada data transaksi untuk filter ini
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-clean table-hover mb-0">
          <thead>
            <tr>
              <th class="ps-3">#</th>
              <th>Periode</th>
              <th>Kabupaten</th>
              <th>Penyuluh</th>
              <th>Kelompok</th>
              <th>Kategori</th>
              <th>Produk</th>
              <th>Jumlah</th>
              <th>Nilai Transaksi</th>
            </tr>
          </thead>
          <tbody>
            <?php $i = $offset + 1; while($tr = $transaksi_rows->fetch_assoc()): ?>
            <tr>
              <td class="ps-3 text-muted"><?=$i++?></td>
              <td style="font-size:12px;white-space:nowrap"><?=$tr['tahun']?>-<?=str_pad((string)$tr['bulan'], 2, '0', STR_PAD_LEFT)?> (<?=$bulan_names[(int)$tr['bulan']] ?? '-'?>)</td>
              <td><span class="badge bg-light text-dark border" style="font-size:10.5px"><?=htmlspecialchars($tr['nama_kabupaten'] ?? '-')?></span></td>
              <td style="font-size:12.5px"><?=htmlspecialchars($tr['nama_penyuluh'] ?? '-')?></td>
              <td style="font-size:12px"><?=htmlspecialchars($tr['nama_kelompok'] ?: '-')?></td>
              <td style="font-size:12px"><?=htmlspecialchars($tr['nama_kategori'] ?: '-')?></td>
              <td style="font-size:12px"><?=htmlspecialchars($tr['nama_produk'] ?: '-')?></td>
              <td style="font-size:12px"><?=format_tanpa_pembulatan((float)$tr['jumlah_penjualan'], 2)?> <?=htmlspecialchars($tr['satuan'] ?: '')?></td>
              <td style="font-size:12.5px;font-weight:600">Rp <?=format_tanpa_pembulatan((float)$tr['nte_kth'], 2)?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <?php if ($totalPages > 1): ?>
  <div class="card-footer bg-white">
    <nav>
      <ul class="pagination pagination-sm mb-0 justify-content-end">
        <li class="page-item <?=$page<=1?'disabled':''?>">
          <a class="page-link" href="<?=$page<=1?'#':page_url($page-1, $queryBase)?>">Sebelumnya</a>
        </li>
        <?php
          $start = max(1, $page - 2);
          $end = min($totalPages, $page + 2);
          for ($p = $start; $p <= $end; $p++):
        ?>
          <li class="page-item <?=$p==$page?'active':''?>">
            <a class="page-link" href="<?=page_url($p, $queryBase)?>"><?=$p?></a>
          </li>
        <?php endfor; ?>
        <li class="page-item <?=$page>=$totalPages?'disabled':''?>">
          <a class="page-link" href="<?=$page>=$totalPages?'#':page_url($page+1, $queryBase)?>">Berikutnya</a>
        </li>
      </ul>
    </nav>
  </div>
  <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>



