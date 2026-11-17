<?php
// Unit test CLI untuk parsing import NTE. Tanpa DB.
// Jalankan: php tests/test_import_parsing.php   (exit 0 = lolos semua)
require_once __DIR__ . '/../config.php';

$fail = 0;
$total = 0;

function check(string $name, $actual, $expected, float $eps = 1e-9): void {
    global $fail, $total;
    $total++;
    $ok = is_float($expected)
        ? (is_numeric($actual) && abs((float) $actual - $expected) < $eps)
        : ($actual === $expected);
    if ($ok) {
        echo "ok   $name\n";
    } else {
        $fail++;
        echo "FAIL $name: didapat " . var_export($actual, true) . ", harap " . var_export($expected, true) . "\n";
    }
}

// ── 1. parse_localized_number: nilai sample nyata (titik = desimal) ──
check("jumlah '5'", parse_localized_number('5'), 5.0);
check("jumlah '5.22'", parse_localized_number('5.22'), 5.22);
check("jumlah '1.5'", parse_localized_number('1.5'), 1.5);
check("jumlah '4.11'", parse_localized_number('4.11'), 4.11);
check("jumlah '4.15'", parse_localized_number('4.15'), 4.15);
check("jumlah '4.12'", parse_localized_number('4.12'), 4.12);
check("jumlah numerik 5.22", parse_localized_number(5.22), 5.22);
check("NTE integer '5000000'", parse_localized_number('5000000'), 5000000.0);
check("NTE integer '5220000'", parse_localized_number('5220000'), 5220000.0);

// ── 2. Regresi format lokal (titik ribuan / koma desimal tetap benar) ──
// String satu titik diartikan desimal ala PHP (is_numeric fast path).
// Ambigu dengan ribuan, tapi data sumber memakai titik-desimal (5.22).
check("satu titik '900.000' = desimal", parse_localized_number('900.000'), 900.0);
check("ribuan '4.500.000'", parse_localized_number('4.500.000'), 4500000.0);
check("desimal koma '2,5'", parse_localized_number('2,5'), 2.5);
check("ribuan koma '1,250,000'", parse_localized_number('1,250,000'), 1250000.0);
check("campur '9.000.000,50'", parse_localized_number('9.000.000,50'), 9000000.5);
check("campur '9,000,000.50'", parse_localized_number('9,000,000.50'), 9000000.5);
check("kosong ''", parse_localized_number(''), 0.0);
check("Rp 'Rp 5.600.000'", parse_localized_number('Rp 5.600.000'), 5600000.0);
check("negatif '-3,5'", parse_localized_number('-3,5'), -3.5);

// ── 3. Mapping header: urutan baku ──
$headerBaku = ['No.', 'Provinsi', 'Kab/Kota', 'Nama Kelompok', 'Tahun', 'Bulan', 'Barang/Jasa', 'Jenis Produk', 'Jumlah Penjualan', 'Satuan', 'NTE KTH (Rp.)', 'Penyuluh'];
$map = map_import_columns($headerBaku);
check("map provinsi", $map['provinsi'], 1);
check("map kabupaten", $map['kabupaten'], 2);
check("map nama_kelompok", $map['nama_kelompok'], 3);
check("map tahun", $map['tahun'], 4);
check("map bulan", $map['bulan'], 5);
check("map barang_jasa", $map['barang_jasa'], 6);
check("map jenis_produk", $map['jenis_produk'], 7);
check("map jumlah", $map['jumlah'], 8);
check("map satuan", $map['satuan'], 9);
check("map nte", $map['nte'], 10);
check("map penyuluh", $map['penyuluh'], 11);

// ── 4. Mapping header: urutan ACAK tetap benar (poin utamanya) ──
$headerAcak = ['Penyuluh', 'NTE KTH (Rp.)', 'Satuan', 'Jumlah Penjualan', 'Jenis Produk', 'Barang/Jasa', 'Bulan', 'Tahun', 'Nama Kelompok', 'Kab/Kota', 'Provinsi', 'No.'];
$map2 = map_import_columns($headerAcak);
check("acak: penyuluh->0", $map2['penyuluh'], 0);
check("acak: nte->1", $map2['nte'], 1);
check("acak: jumlah->3", $map2['jumlah'], 3);
check("acak: tahun->7", $map2['tahun'], 7);
check("acak: provinsi->10", $map2['provinsi'], 10);

// ── 5. Header rusak DITOLAK dengan pesan jelas ──
try {
    map_import_columns(['No.', 'Provinsi', 'Kab/Kota', 'Nama Kelompok', 'Tahun', 'Bulan', 'Barang/Jasa', 'Jenis Produk', 'Jumlah Penjualan', 'Satuan', 'NTE KTH (Rp.)']);
    check("header tanpa Penyuluh ditolak", false, true);
} catch (RuntimeException $e) {
    check("header tanpa Penyuluh ditolak", str_contains($e->getMessage(), 'Penyuluh'), true);
}
try {
    map_import_columns(['No.', 'Provinsi', 'Kab/Kota', 'Nama Kelompok', 'Tahun', 'Bulan', 'Barang/Jasa', 'Jenis Produk', 'Jumlah Penjualan', 'Satuan', 'NTE', 'NTE KTH (Rp.)', 'Penyuluh']);
    check("header NTE ganda ditolak", false, true);
} catch (RuntimeException $e) {
    check("header NTE ganda ditolak", str_contains($e->getMessage(), '2 kali'), true);
}

// ── 6. Validasi baris ──
check("baris valid", validate_import_row('JAWA TIMUR', 'NGANJUK', 'Sujarwo, SP', '2026', '1', 5.22, 5220000.0, 2020, 2027), null);
check("penyuluh kosong", validate_import_row('JAWA TIMUR', 'NGANJUK', '', '2026', '1', 5.0, 5000000.0, 2020, 2027), 'kolom Penyuluh kosong');
check("kab kosong", validate_import_row('JAWA TIMUR', '', 'Sujarwo, SP', '2026', '1', 5.0, 5000000.0, 2020, 2027), 'Provinsi/Kabupaten kosong');
check("tahun 2019", validate_import_row('JAWA TIMUR', 'NGANJUK', 'Sujarwo, SP', '2019', '1', 5.0, 5000000.0, 2020, 2027), "tahun '2019' di luar rentang 2020-2027");
check("tahun 2030", validate_import_row('JAWA TIMUR', 'NGANJUK', 'Sujarwo, SP', '2030', '1', 5.0, 5000000.0, 2020, 2027), "tahun '2030' di luar rentang 2020-2027");
check("tahun teks", validate_import_row('JAWA TIMUR', 'NGANJUK', 'Sujarwo, SP', 'Tahun', '1', 5.0, 5000000.0, 2020, 2027), "tahun 'Tahun' di luar rentang 2020-2027");
check("bulan 13", validate_import_row('JAWA TIMUR', 'NGANJUK', 'Sujarwo, SP', '2026', '13', 5.0, 5000000.0, 2020, 2027), "bulan '13' tidak valid (harus 1-12)");
check("bulan 0", validate_import_row('JAWA TIMUR', 'NGANJUK', 'Sujarwo, SP', '2026', '0', 5.0, 5000000.0, 2020, 2027), "bulan '0' tidak valid (harus 1-12)");
check("bulan kosong", validate_import_row('JAWA TIMUR', 'NGANJUK', 'Sujarwo, SP', '2026', '', 5.0, 5000000.0, 2020, 2027), "bulan '' tidak valid (harus 1-12)");
check("jumlah negatif", validate_import_row('JAWA TIMUR', 'NGANJUK', 'Sujarwo, SP', '2026', '1', -1.0, 5000000.0, 2020, 2027), 'Jumlah Penjualan negatif');
check("nte negatif", validate_import_row('JAWA TIMUR', 'NGANJUK', 'Sujarwo, SP', '2026', '1', 5.0, -100.0, 2020, 2027), 'NTE KTH negatif');

// ── 7. Ambang anomali harga ──
check("normal 5%", deviasi_harga(1260000.0, 1200000.0), null);
check("batas 25% pas", deviasi_harga(1500000.0, 1200000.0), null);
check("anomali 33%", deviasi_harga(800000.0, 1200000.0) !== null, true);
check("anomali nilainya", deviasi_harga(800000.0, 1200000.0), 100.0 * (400000.0 / 1200000.0), 1e-6);
check("rata nol", deviasi_harga(800000.0, 0.0), null);

echo "\n$total assertions, $fail gagal\n";
exit($fail > 0 ? 1 : 0);
