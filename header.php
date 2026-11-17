<?php
$appLogoPath = __DIR__ . '/' . APP_LOGO;
$appLogoOk = is_file($appLogoPath);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? h($pageTitle) . ' - ' : '' ?><?= h(APP_NAME) ?> - <?= h(APP_BRAND_ORG) ?></title>
  <meta name="description" content="<?= h(APP_NAME . ' ' . APP_BRAND_ORG . ' - ' . APP_BRAND_PARENT) ?>">
  <?php if ($appLogoOk): ?>
  <link rel="icon" type="image/png" href="<?= h(APP_LOGO) ?>">
  <link rel="apple-touch-icon" href="<?= h(APP_LOGO) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css">
  <?php if (($pageTitle ?? '') === 'Dashboard'): ?>
  <script>
  window.tailwind = window.tailwind || {};
  window.tailwind.config = { corePlugins: { preflight: false } };
  </script>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php endif; ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
</head>
<body>

<!-- Sidebar -->
<nav id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-bar" aria-hidden="true"></div>
    <div class="d-flex gap-3 align-items-start">
      <?php if ($appLogoOk): ?>
      <div class="brand-logo-wrap">
        <img src="<?= h(APP_LOGO) ?>" alt="<?= h(APP_BRAND_ORG) ?>" class="brand-logo-img" width="72" height="52" loading="eager" decoding="async">
      </div>
      <?php else: ?>
      <div class="brand-icon flex-shrink-0"><i class="bi bi-tree-fill text-white fs-5"></i></div>
      <?php endif; ?>
      <div class="min-w-0">
        <div class="brand-org-title">Cabang Dinas Kehutanan</div>
        <div class="brand-org-sub">Wilayah Bojonegoro</div>
        <h6 class="brand-app mb-0"><?= h(APP_NAME) ?></h6>
        <small><?= h(APP_BRAND_PARENT) ?> - Nilai Transaksi Ekonomi KTH</small>
      </div>
    </div>
  </div>
  <div class="sidebar-nav">
    <div class="nav-section-label">Menu Utama</div>
    <a href="index.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='index.php')?'active':'' ?>">
      <i class="bi bi-speedometer2"></i> Dashboard
    </a>
    <a href="import.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='import.php')?'active':'' ?>">
      <i class="bi bi-file-earmark-arrow-up"></i> Import Data
    </a>
    <a href="target.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='target.php')?'active':'' ?>">
      <i class="bi bi-bullseye"></i> Input Target NTE
    </a>
    <a href="import_target.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='import_target.php')?'active':'' ?>">
      <i class="bi bi-file-earmark-spreadsheet"></i> Import Target
    </a>
    <div class="nav-section-label mt-2">Laporan</div>
    <a href="laporan_penyuluh.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='laporan_penyuluh.php')?'active':'' ?>">
      <i class="bi bi-people"></i> Status Penyuluh
    </a>
    <a href="laporan_produk.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='laporan_produk.php')?'active':'' ?>">
      <i class="bi bi-bar-chart-line"></i> Realisasi per Produk
    </a>
    <a href="laporan_realisasi_penyuluh.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='laporan_realisasi_penyuluh.php')?'active':'' ?>">
      <i class="bi bi-person-lines-fill"></i> Realisasi per Penyuluh
    </a>
    <a href="transaksi.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='transaksi.php')?'active':'' ?>">
      <i class="bi bi-table"></i> Detail Transaksi
    </a>
    <div class="nav-section-label mt-2">Master Data</div>
    <a href="master_penyuluh.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='master_penyuluh.php')?'active':'' ?>">
      <i class="bi bi-person-badge"></i> Master Penyuluh
    </a>
    <a href="merge_penyuluh.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='merge_penyuluh.php')?'active':'' ?>">
      <i class="bi bi-intersect"></i> Gabung Duplikat
    </a>
  </div>
  <div class="sidebar-footer">
    <div style="color:#718096;font-weight:600;margin-bottom:4px"><?= h(APP_BRAND_ORG) ?></div>
    <div style="font-size:10px;color:#a0aec0;line-height:1.35"><?= h(APP_BRAND_PARENT) ?></div>
    <div class="mt-2" style="font-size:10px;color:#cbd5e0">&copy; <?= date('Y') ?> - <?= h(APP_NAME) ?></div>
  </div>
</nav>
<div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

<!-- Topbar -->
<div id="topbar">
  <div class="d-flex align-items-center gap-3 min-w-0">
    <button type="button" class="btn btn-light border topbar-menu-btn" id="sidebarToggle" aria-label="Buka menu" aria-controls="sidebar" aria-expanded="false">
      <i class="bi bi-list"></i>
    </button>
    <div class="min-w-0">
    <div class="page-title"><?= isset($pageTitle) ? h($pageTitle) : 'Dashboard' ?></div>
    <div class="topbar-brand-line d-none d-md-block"><?= h(APP_BRAND_ORG) ?> - <?= h(APP_NAME) ?></div>
    <div class="topbar-brand-line d-md-none"><?= h(APP_BRAND_ORG_SHORT) ?> - <?= h(APP_NAME) ?></div>
    <nav aria-label="breadcrumb" class="mt-1">
      <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="index.php">Beranda</a></li>
        <?php if(isset($pageTitle) && $pageTitle != 'Dashboard'): ?>
        <li class="breadcrumb-item active"><?= h($pageTitle) ?></li>
        <?php endif; ?>
      </ol>
    </nav>
    </div>
  </div>
  <div class="d-flex align-items-center gap-3">
    <span class="badge bg-light text-dark border topbar-date" style="font-size:11px;">
      <i class="bi bi-calendar3 me-1"></i><?= date('d M Y') ?>
    </span>
    <div class="d-flex align-items-center gap-2 topbar-user">
      <div style="width:34px;height:34px;background:var(--primary-light);border-radius:50%;display:flex;align-items:center;justify-content:center;">
        <i class="bi bi-person-fill text-success"></i>
      </div>
      <span style="font-size:13px;font-weight:500;">Admin</span>
    </div>
  </div>
</div>

<!-- Main Content -->
<div id="main-content">


