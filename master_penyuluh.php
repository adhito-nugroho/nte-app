<?php
require_once 'config.php';
$pageTitle = 'Master Penyuluh';
$db = getDB();

$msg = ''; $msgType = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_penyuluh') {
        $nama = trim((string) ($_POST['nama_penyuluh'] ?? ''));
        $kab_id = (int)$_POST['kabupaten_id'];
        if (!$nama || !$kab_id) {
            $msg = 'Nama penyuluh dan kabupaten wajib diisi.'; $msgType = 'danger';
        } else {
            $stmt = $db->prepare("SELECT id FROM penyuluh WHERE nama_penyuluh=? AND kabupaten_id=?");
            $stmt->bind_param('si', $nama, $kab_id);
            $stmt->execute();
            $check = $stmt->get_result();
            if ($check->num_rows > 0) {
                $msg = 'Penyuluh dengan nama tersebut sudah ada di kabupaten ini.'; $msgType = 'warning';
            } else {
                $stmt = $db->prepare("INSERT INTO penyuluh (nama_penyuluh, kabupaten_id) VALUES (?, ?)");
                $stmt->bind_param('si', $nama, $kab_id);
                $stmt->execute();
                $msg = 'Penyuluh berhasil ditambahkan.'; $msgType = 'success';
            }
        }
    } elseif ($action === 'edit_penyuluh') {
        $id = (int)$_POST['penyuluh_id'];
        $nama = trim((string) ($_POST['nama_penyuluh'] ?? ''));
        $kab_id = (int)$_POST['kabupaten_id'];
        $aktif = isset($_POST['aktif']) ? 1 : 0;
        if (!$id || !$nama || !$kab_id) {
            $msg = 'Nama penyuluh dan kabupaten wajib diisi.'; $msgType = 'danger';
        } else {
            $stmt = $db->prepare("UPDATE penyuluh SET nama_penyuluh=?, kabupaten_id=?, aktif=? WHERE id=?");
            $stmt->bind_param('siii', $nama, $kab_id, $aktif, $id);
            $stmt->execute();
            $msg = 'Data penyuluh berhasil diperbarui.'; $msgType = 'success';
        }
    } elseif ($action === 'delete_penyuluh') {
        $id = (int)$_POST['penyuluh_id'];
        $stmt = $db->prepare("SELECT COUNT(*) as c FROM data_nte WHERE penyuluh_id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $cek = $stmt->get_result()->fetch_assoc()['c'];
        if ($cek > 0) {
            $msg = "Tidak bisa dihapus, penyuluh memiliki $cek data NTE. Nonaktifkan saja."; $msgType = 'warning';
        } else {
            $stmt = $db->prepare("DELETE FROM penyuluh WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $msg = 'Penyuluh berhasil dihapus.'; $msgType = 'success';
        }
    }
}

$kab_filter = isset($_GET['kab_id']) ? (int)$_GET['kab_id'] : 0;
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$whereParts = [];
$types = '';
$params = [];
if ($kab_filter) {
    $whereParts[] = 'p.kabupaten_id=?';
    $types .= 'i';
    $params[] = $kab_filter;
}
if ($search !== '') {
    $whereParts[] = 'p.nama_penyuluh LIKE ?';
    $types .= 's';
    $params[] = '%' . $search . '%';
}
$whereSql = $whereParts ? ' AND ' . implode(' AND ', $whereParts) : '';

$sqlPenyuluh = "
    SELECT p.id, p.nama_penyuluh, p.aktif, p.created_at,
           k.id as kab_id, k.nama_kabupaten,
           (SELECT COUNT(*) FROM data_nte WHERE penyuluh_id=p.id) as jumlah_data,
           (SELECT COALESCE(SUM(nte_kth),0) FROM data_nte WHERE penyuluh_id=p.id) as total_nte
    FROM penyuluh p
    JOIN kabupaten k ON p.kabupaten_id=k.id
    WHERE 1=1 $whereSql
    ORDER BY k.nama_kabupaten, p.nama_penyuluh
";
$stmt = $db->prepare($sqlPenyuluh);
bind_statement_params($stmt, $types, $params);
$stmt->execute();
$penyuluh_list = $stmt->get_result();

$kabupaten_list = $db->query("SELECT id, nama_kabupaten FROM kabupaten ORDER BY nama_kabupaten");

require_once 'header.php';


?>

<div class="d-flex justify-content-end align-items-center gap-2 mb-4">
  <a href="merge_penyuluh.php" class="btn btn-outline-warning btn-sm">
    <i class="bi bi-intersect me-1"></i>Gabung Duplikat
  </a>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalTambah">
    <i class="bi bi-plus-lg me-1"></i>Tambah Penyuluh
  </button>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?=$msgType?> alert-dismissible fade show">
  <i class="bi bi-<?=$msgType=='success'?'check-circle-fill':($msgType=='danger'?'x-circle-fill':'exclamation-triangle-fill')?> me-2"></i><?=$msg?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Filter -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label mb-1" style="font-size:12px">Cari Nama</label>
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Ketik nama penyuluh..." value="<?=h($search)?>">
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:12px">Kabupaten</label>
        <select name="kab_id" class="form-select form-select-sm">
          <option value="">Semua Kabupaten</option>
          <?php $kabupaten_list->data_seek(0); while($k=$kabupaten_list->fetch_assoc()): ?>
          <option value="<?=$k['id']?>" <?=$k['id']==$kab_filter?'selected':''?>><?=h($k['nama_kabupaten'])?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Cari</button>
      </div>
      <div class="col-md-2">
        <a href="master_penyuluh.php" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6><i class="bi bi-person-badge me-2 text-success"></i>Daftar Penyuluh</h6>
    <span class="badge bg-light text-dark border"><?=$penyuluh_list->num_rows?> penyuluh</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-clean table-hover mb-0">
        <thead>
          <tr>
            <th class="ps-3">#</th>
            <th>Nama Penyuluh</th>
            <th>Kabupaten</th>
            <th>Status</th>
            <th>Jml. Data</th>
            <th>Total NTE</th>
            <th>Terdaftar</th>
            <th style="text-align:center">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php $i=1; while($p=$penyuluh_list->fetch_assoc()): ?>
          <tr>
            <td class="ps-3 text-muted" style="font-size:12px"><?=$i++?></td>
            <td>
              <div style="font-size:13.5px;font-weight:500"><?=htmlspecialchars($p['nama_penyuluh'])?></div>
            </td>
            <td><span class="badge bg-light text-dark border" style="font-size:10.5px"><?=h($p['nama_kabupaten'])?></span></td>
            <td>
              <?php if($p['aktif']): ?>
                <span class="badge badge-sudah"><i class="bi bi-circle-fill me-1" style="font-size:7px"></i>Aktif</span>
              <?php else: ?>
                <span class="badge badge-belum"><i class="bi bi-circle-fill me-1" style="font-size:7px"></i>Nonaktif</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if($p['jumlah_data']>0): ?>
                <span class="badge" style="background:#ebf8ff;color:#2b6cb0;font-size:11px"><?=number_format($p['jumlah_data'])?> data</span>
              <?php else: ?>
                <span class="text-muted" style="font-size:12px">-</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12.5px;font-weight:<?=$p['total_nte']>0?'600':'400'?>;color:<?=$p['total_nte']>0?'#1a202c':'#a0aec0'?>">
              <?=$p['total_nte']>0?rupiah($p['total_nte']):'-'?>
            </td>
            <td style="font-size:11.5px;color:#718096"><?=date('d/m/Y', strtotime($p['created_at']))?></td>
            <td style="text-align:center">
              <div class="d-flex gap-1 justify-content-center">
                <button class="btn btn-sm btn-outline-primary" style="padding:3px 8px"
                  data-edit-id="<?=$p['id']?>"
                  data-edit-nama="<?=h($p['nama_penyuluh'])?>"
                  data-edit-kab="<?=$p['kab_id']?>"
                  data-edit-aktif="<?=$p['aktif']?>"
                  onclick="openEditFromButton(this)"
                  data-bs-toggle="modal" data-bs-target="#modalEdit">
                  <i class="bi bi-pencil"></i>
                </button>
                <form method="POST" style="display:inline" onsubmit="return confirm('Yakin hapus penyuluh ini?')">
                  <input type="hidden" name="action" value="delete_penyuluh">
                  <input type="hidden" name="penyuluh_id" value="<?=$p['id']?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" style="padding:3px 8px">
                    <i class="bi bi-trash3"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal Tambah -->
<div class="modal fade" id="modalTambah" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:14px;border:none">
      <div class="modal-header" style="background:var(--primary);border-radius:14px 14px 0 0">
        <h6 class="modal-title text-white"><i class="bi bi-person-plus me-2"></i>Tambah Penyuluh</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body p-4">
          <input type="hidden" name="action" value="add_penyuluh">
          <div class="mb-3">
            <label class="form-label">Nama Penyuluh <span class="text-danger">*</span></label>
            <input type="text" name="nama_penyuluh" class="form-control" placeholder="Masukkan nama lengkap..." required>
          </div>
          <div class="mb-3">
            <label class="form-label">Kabupaten <span class="text-danger">*</span></label>
            <select name="kabupaten_id" class="form-select" required>
              <option value="">-- Pilih Kabupaten --</option>
              <?php $kabupaten_list->data_seek(0); while($k=$kabupaten_list->fetch_assoc()): ?>
              <option value="<?=$k['id']?>"><?=h($k['nama_kabupaten'])?></option>
              <?php endwhile; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:14px;border:none">
      <div class="modal-header" style="background:var(--primary);border-radius:14px 14px 0 0">
        <h6 class="modal-title text-white"><i class="bi bi-pencil me-2"></i>Edit Penyuluh</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body p-4">
          <input type="hidden" name="action" value="edit_penyuluh">
          <input type="hidden" name="penyuluh_id" id="editId">
          <div class="mb-3">
            <label class="form-label">Nama Penyuluh <span class="text-danger">*</span></label>
            <input type="text" name="nama_penyuluh" id="editNama" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Kabupaten <span class="text-danger">*</span></label>
            <select name="kabupaten_id" id="editKab" class="form-select" required>
              <option value="">-- Pilih Kabupaten --</option>
              <?php $kabupaten_list->data_seek(0); while($k=$kabupaten_list->fetch_assoc()): ?>
              <option value="<?=$k['id']?>"><?=h($k['nama_kabupaten'])?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="aktif" id="editAktif" value="1">
            <label class="form-check-label" for="editAktif" style="font-size:13px">Penyuluh Aktif</label>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJS = <<<JS
<script>
function openEdit(id, nama, kabId, aktif) {
  document.getElementById('editId').value = id;
  document.getElementById('editNama').value = nama;
  document.getElementById('editKab').value = kabId;
  document.getElementById('editAktif').checked = aktif == 1;
}
function openEditFromButton(btn) {
  openEdit(btn.dataset.editId, btn.dataset.editNama, btn.dataset.editKab, btn.dataset.editAktif);
}
</script>
JS;
require_once 'footer.php';
?>


