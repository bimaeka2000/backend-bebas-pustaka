<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

setCORSHeaders();
if (getMethod() !== 'GET') respond(false, null, 'Method tidak didukung', 405);

$db = getDB();

// ── Statistik Utama ───────────────────────────────────────────
$total_mahasiswa = $db->query('SELECT COUNT(*) FROM mahasiswa')->fetchColumn();
$total_petugas   = $db->query('SELECT COUNT(*) FROM petugas')->fetchColumn();

$pengajuan_pending  = $db->query("SELECT COUNT(*) FROM pengajuan_bebas_pustaka WHERE status IN ('menunggu','diproses')")->fetchColumn();
$pengajuan_disetujui= $db->query("SELECT COUNT(*) FROM pengajuan_bebas_pustaka WHERE status = 'disetujui'")->fetchColumn();
$pengajuan_ditolak  = $db->query("SELECT COUNT(*) FROM pengajuan_bebas_pustaka WHERE status = 'ditolak'")->fetchColumn();

$surat_terbit   = $db->query('SELECT COUNT(*) FROM surat_bebas_pustaka')->fetchColumn();

$ta_menunggu    = $db->query("SELECT COUNT(*) FROM penyerahan_ta WHERE status_verifikasi = 'menunggu'")->fetchColumn();
$ta_diverifikasi= $db->query("SELECT COUNT(*) FROM penyerahan_ta WHERE status_verifikasi = 'diverifikasi'")->fetchColumn();

$ada_tunggakan  = $db->query('SELECT COUNT(*) FROM riwayat_pinjaman WHERE tunggakan_denda > 0')->fetchColumn();
$ada_pinjaman   = $db->query('SELECT COUNT(*) FROM riwayat_pinjaman WHERE buku_dipinjam > 0')->fetchColumn();

$total_tunggakan= $db->query('SELECT COALESCE(SUM(tunggakan_denda),0) FROM riwayat_pinjaman')->fetchColumn();

// ── Pengajuan per Bulan (12 bulan terakhir) ──────────────────
$pengajuan_per_bulan = $db->query(
    "SELECT DATE_FORMAT(tgl_pengajuan, '%Y-%m') AS bulan,
            COUNT(*) AS total,
            SUM(status = 'disetujui') AS disetujui,
            SUM(status = 'ditolak')   AS ditolak
     FROM pengajuan_bebas_pustaka
     WHERE tgl_pengajuan >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
     GROUP BY bulan
     ORDER BY bulan ASC"
)->fetchAll();

// ── Pengajuan Terbaru (10) ────────────────────────────────────
$pengajuan_terbaru = $db->query(
    'SELECT pb.id, pb.nim, pb.tgl_pengajuan, pb.status,
            m.nama, j.nama_jurusan
     FROM pengajuan_bebas_pustaka pb
     JOIN mahasiswa m ON m.nim = pb.nim
     JOIN jurusan j ON j.id = m.id_jurusan
     ORDER BY pb.tgl_pengajuan DESC
     LIMIT 10'
)->fetchAll();

// ── Penyerahan TA Menunggu Verifikasi ────────────────────────
$ta_terbaru = $db->query(
    "SELECT pt.id, pt.nim, pt.judul_ta, pt.status_verifikasi, pt.tgl_penyerahan,
            m.nama, j.nama_jurusan
     FROM penyerahan_ta pt
     JOIN mahasiswa m ON m.nim = pt.nim
     JOIN jurusan j ON j.id = m.id_jurusan
     WHERE pt.status_verifikasi = 'menunggu'
     ORDER BY pt.tgl_penyerahan DESC
     LIMIT 5"
)->fetchAll();

// ── Distribusi Mahasiswa per Jurusan ─────────────────────────
$distribusi_jurusan = $db->query(
    'SELECT j.nama_jurusan, j.fakultas, COUNT(m.nim) AS jumlah
     FROM jurusan j
     LEFT JOIN mahasiswa m ON m.id_jurusan = j.id
     GROUP BY j.id
     ORDER BY jumlah DESC'
)->fetchAll();

respond(true, [
    'statistik' => [
        'total_mahasiswa'     => (int)$total_mahasiswa,
        'total_petugas'       => (int)$total_petugas,
        'pengajuan_pending'   => (int)$pengajuan_pending,
        'pengajuan_disetujui' => (int)$pengajuan_disetujui,
        'pengajuan_ditolak'   => (int)$pengajuan_ditolak,
        'surat_terbit'        => (int)$surat_terbit,
        'ta_menunggu'         => (int)$ta_menunggu,
        'ta_diverifikasi'     => (int)$ta_diverifikasi,
        'ada_tunggakan'       => (int)$ada_tunggakan,
        'ada_pinjaman'        => (int)$ada_pinjaman,
        'total_tunggakan'     => (float)$total_tunggakan,
    ],
    'pengajuan_per_bulan' => $pengajuan_per_bulan,
    'pengajuan_terbaru'   => $pengajuan_terbaru,
    'ta_menunggu_list'    => $ta_terbaru,
    'distribusi_jurusan'  => $distribusi_jurusan,
]);
