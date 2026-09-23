<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

setCORSHeaders();
$db     = getDB();
$method = getMethod();
$id     = $_GET['id'] ?? null;
$action = $_GET['action'] ?? $_POST['action'] ?? null;

// Pastikan kolom ttd ada pada petugas jika belum ada
try {
    $db->exec("ALTER TABLE petugas ADD COLUMN ttd VARCHAR(255) NULL");
} catch (Exception $e) {
}

/* ─────────────────────────────────────────────────────────────
   HELPER: Ambil data petugas berdasarkan ID
   ───────────────────────────────────────────────────────────── */
if (!function_exists('getPetugasById')) {
    function getPetugasById(PDO $db, int $petugasId): ?array
    {
        if ($petugasId <= 0) return null;
        $stmt = $db->prepare('SELECT id, nama, nip, jabatan FROM petugas WHERE id = ?');
        $stmt->execute([$petugasId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

/* ─────────────────────────────────────────────────────────────
   HELPER: Cek apakah seorang petugas adalah Kepala Perpustakaan
   ───────────────────────────────────────────────────────────── */
if (!function_exists('isKepalaPerpustakaan')) {
    function isKepalaPerpustakaan(PDO $db, int $petugasId): bool
    {
        $row = getPetugasById($db, $petugasId);
        if (!$row) return false;
        return stripos($row['jabatan'] ?? '', 'kepala') !== false;
    }
}

/* ─────────────────────────────────────────────────────────────
   HELPER: Ambil petugas (id & jabatan) dari sebuah surat
   ───────────────────────────────────────────────────────────── */
if (!function_exists('getPetugasBySurat')) {
    function getPetugasBySurat(PDO $db, int $suratId): ?array
    {
        $stmt = $db->prepare(
            'SELECT p.id, p.nama, p.jabatan
             FROM surat_bebas_pustaka s
             JOIN petugas p ON p.id = s.id_petugas
             WHERE s.id = ?'
        );
        $stmt->execute([$suratId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

/* ─────────────────────────────────────────────────────────────
   HELPER: Validasi hak akses upload TTD (berdasarkan USER LOGIN)
   Memberikan pesan error yang jelas & spesifik
   ───────────────────────────────────────────────────────────── */
if (!function_exists('assertBolehUploadTtd')) {
    function assertBolehUploadTtd(PDO $db, int $petugasId): array
    {
        if ($petugasId <= 0) {
            respond(false, null, 'Identitas pengguna (id_petugas) tidak dikirim. Silakan login ulang.', 400);
        }

        $petugas = getPetugasById($db, $petugasId);
        if (!$petugas) {
            respond(false, null, 'Data petugas dengan ID ' . $petugasId . ' tidak ditemukan di sistem.', 404);
        }

        if (stripos($petugas['jabatan'] ?? '', 'kepala') === false) {
            respond(
                false,
                [
                    'user_id'  => $petugas['id'],
                    'user'     => $petugas['nama'],
                    'jabatan'  => $petugas['jabatan'] ?: 'Tidak diketahui',
                ],
                'Akses ditolak: jabatan Anda "' . ($petugas['jabatan'] ?: 'Tidak diketahui') . '" bukan Kepala Perpustakaan. Hanya Kepala Perpustakaan yang berhak memproses tanda tangan digital.',
                403
            );
        }

        return $petugas;
    }
}

switch ($method) {
    case 'GET':
        if ($id) {
            $stmt = $db->prepare(
                'SELECT s.*, pb.nim, pb.tgl_pengajuan, pb.status AS status_pengajuan,
                        m.nama, m.email, j.nama_jurusan, j.fakultas, j.gelar,
                        p.nama AS nama_petugas, p.jabatan, p.nip AS nip_petugas, p.ttd AS ttd_petugas
                 FROM surat_bebas_pustaka s
                 JOIN pengajuan_bebas_pustaka pb ON pb.id = s.id_pengajuan
                 JOIN mahasiswa m ON m.nim = pb.nim
                 JOIN jurusan j ON j.id = m.id_jurusan
                 JOIN petugas p ON p.id = s.id_petugas
                 WHERE s.id = ?'
            );
            $stmt->execute([$id]);
            $data = $stmt->fetch();
            if (!$data) respond(false, null, 'Surat tidak ditemukan', 404);
            respond(true, $data);
        } else {
            $nimFilter = $_GET['nim'] ?? null;
            if ($nimFilter) {
                $stmt = $db->prepare(
                    'SELECT s.*, pb.nim, pb.tgl_pengajuan, pb.status AS status_pengajuan,
                            m.nama, m.email, j.nama_jurusan, j.fakultas, j.gelar,
                            p.nama AS nama_petugas, p.jabatan, p.nip AS nip_petugas, p.ttd AS ttd_petugas
                     FROM surat_bebas_pustaka s
                     JOIN pengajuan_bebas_pustaka pb ON pb.id = s.id_pengajuan
                     JOIN mahasiswa m ON m.nim = pb.nim
                     JOIN jurusan j ON j.id = m.id_jurusan
                     JOIN petugas p ON p.id = s.id_petugas
                     WHERE pb.nim = ?
                     ORDER BY s.tgl_terbit DESC'
                );
                $stmt->execute([$nimFilter]);
                respond(true, $stmt->fetchAll());
            } else {
                $stmt = $db->query(
                    'SELECT s.*, pb.nim, pb.tgl_pengajuan, pb.status AS status_pengajuan,
                            m.nama, m.email, j.nama_jurusan, j.fakultas, j.gelar,
                            p.nama AS nama_petugas, p.jabatan, p.nip AS nip_petugas, p.ttd AS ttd_petugas
                     FROM surat_bebas_pustaka s
                     JOIN pengajuan_bebas_pustaka pb ON pb.id = s.id_pengajuan
                     JOIN mahasiswa m ON m.nim = pb.nim
                     JOIN jurusan j ON j.id = m.id_jurusan
                     JOIN petugas p ON p.id = s.id_petugas
                     ORDER BY s.tgl_terbit DESC'
                );
                respond(true, $stmt->fetchAll());
            }
        }
        break;

    case 'POST':
        $upload_ttd_dir = __DIR__ . '/../uploads/ttd/';
        if (!is_dir($upload_ttd_dir)) mkdir($upload_ttd_dir, 0755, true);

        // ── 1. PROSES TTD INDIVIDU (1-1) ──────────────────────────
        if ($action === 'sign_single') {
            $surat_id = (int)($_POST['id'] ?? $id ?? 0);
            if (!$surat_id) {
                respond(false, null, 'ID surat diperlukan untuk proses tanda tangan', 400);
            }

            // Validasi: hanya Kepala Perpustakaan (user login) yang boleh tanda tangan
            $uploaderId = (int)($_POST['id_petugas'] ?? 0);
            assertBolehUploadTtd($db, $uploaderId); // 403 jika bukan kepala

            // Pastikan surat benar-benar ada
            $petInfo = getPetugasBySurat($db, $surat_id);
            if (!$petInfo) {
                respond(false, null, 'Surat dengan ID ' . $surat_id . ' tidak ditemukan.', 404);
            }

            $file_ttd = null;
            if (!empty($_FILES['file_ttd']['name'])) {
                $ext = strtolower(pathinfo($_FILES['file_ttd']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'])) {
                    respond(false, null, 'Format TTD harus PNG, JPG, WEBP, atau SVG', 400);
                }
                $filename = 'ttd_' . $surat_id . '_' . time() . '.' . $ext;
                if (!move_uploaded_file($_FILES['file_ttd']['tmp_name'], $upload_ttd_dir . $filename)) {
                    respond(false, null, 'Gagal mengunggah file tanda tangan', 500);
                }
                $file_ttd = $filename;
            } elseif (!empty($_POST['ttd_filename'])) {
                $file_ttd = trim($_POST['ttd_filename']);
            }

            if (!$file_ttd) {
                respond(false, null, 'File tanda tangan digital atau nama file TTD tersimpan wajib disertakan', 400);
            }

            $stmt = $db->prepare('UPDATE surat_bebas_pustaka SET file_ttd = ? WHERE id = ?');
            $stmt->execute([$file_ttd, $surat_id]);

            // Sinkronkan master TTD ke petugas (hanya jika yang tanda tangan adalah Kepala)
            if (stripos($petInfo['jabatan'] ?? '', 'kepala') !== false) {
                $db->prepare('UPDATE petugas SET ttd = ? WHERE id = ?')
                    ->execute([$file_ttd, $petInfo['id']]);
            }

            respond(true, ['id' => $surat_id, 'file_ttd' => $file_ttd], 'Tanda tangan digital berhasil diproses');
        }

        // ── 2. PROSES TTD MASSAL (BULK) ───────────────────────────
        if ($action === 'sign_bulk') {
            // Validasi: hanya Kepala Perpustakaan (user login) yang boleh bulk sign
            $uploaderId = (int)($_POST['id_petugas'] ?? 0);
            assertBolehUploadTtd($db, $uploaderId); // 403 jika bukan kepala

            $raw_ids = $_POST['ids'] ?? '';
            $ids = [];
            if (is_array($raw_ids)) {
                $ids = array_map('intval', $raw_ids);
            } elseif (is_string($raw_ids)) {
                $decoded = json_decode($raw_ids, true);
                if (is_array($decoded)) {
                    $ids = array_map('intval', $decoded);
                } else {
                    $ids = array_filter(array_map('intval', explode(',', $raw_ids)));
                }
            }

            if (empty($ids)) {
                respond(false, null, 'Daftar ID surat tidak boleh kosong', 400);
            }

            $file_ttd = null;
            if (!empty($_FILES['file_ttd']['name'])) {
                $ext = strtolower(pathinfo($_FILES['file_ttd']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'])) {
                    respond(false, null, 'Format TTD harus PNG, JPG, WEBP, atau SVG', 400);
                }
                $filename = 'ttd_bulk_' . time() . '.' . $ext;
                if (!move_uploaded_file($_FILES['file_ttd']['tmp_name'], $upload_ttd_dir . $filename)) {
                    respond(false, null, 'Gagal mengunggah file tanda tangan massal', 500);
                }
                $file_ttd = $filename;
            } elseif (!empty($_POST['ttd_filename'])) {
                $file_ttd = trim($_POST['ttd_filename']);
            }

            if (!$file_ttd) {
                respond(false, null, 'File tanda tangan digital atau nama file TTD tersimpan wajib disertakan', 400);
            }

            // Update semua surat yang dipilih
            $inClause = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$file_ttd], $ids);
            $stmt = $db->prepare("UPDATE surat_bebas_pustaka SET file_ttd = ? WHERE id IN ($inClause)");
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            // Simpan juga sebagai master TTD milik uploader (sudah dipastikan Kepala)
            $db->prepare('UPDATE petugas SET ttd = ? WHERE id = ?')
                ->execute([$file_ttd, $uploaderId]);

            respond(true, ['total_updated' => $affected, 'file_ttd' => $file_ttd], "$affected surat berhasil ditandatangani secara serentak");
        }

        // ── 3. TERBITKAN SURAT BARU ───────────────────────────────
        $id_pengajuan = (int)($_POST['id_pengajuan'] ?? 0);
        $id_petugas   = (int)($_POST['id_petugas']   ?? 0);
        $nomor_surat  = trim($_POST['nomor_surat']    ?? '');
        if (!$id_pengajuan || !$id_petugas || !$nomor_surat) {
            respond(false, null, 'id_pengajuan, id_petugas, dan nomor_surat wajib diisi', 400);
        }

        // Jika ada indikasi upload / penyertaan TTD, hanya Kepala Perpustakaan yang boleh
        $adaTtdUpload = !empty($_FILES['file_ttd']['name']) || !empty($_POST['ttd_filename']);
        if ($adaTtdUpload) {
            assertBolehUploadTtd($db, $id_petugas); // 403 jika bukan kepala
        }

        // Validasi pengajuan disetujui
        $cek = $db->prepare("SELECT id, status FROM pengajuan_bebas_pustaka WHERE id = ?");
        $cek->execute([$id_pengajuan]);
        $peng = $cek->fetch();
        if (!$peng) respond(false, null, 'Pengajuan tidak ditemukan', 404);
        if ($peng['status'] !== 'disetujui') respond(false, null, 'Pengajuan harus berstatus "disetujui" untuk menerbitkan surat', 422);

        // Upload file PDF surat (opsional)
        $upload_dir = __DIR__ . '/../uploads/surat/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $file_pdf = null;
        if (!empty($_FILES['file_pdf']['name'])) {
            $ext      = pathinfo($_FILES['file_pdf']['name'], PATHINFO_EXTENSION);
            $filename = 'surat_' . $id_pengajuan . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['file_pdf']['tmp_name'], $upload_dir . $filename);
            $file_pdf = $filename;
        }

        // Upload file TTD (tanda tangan digital) atau gunakan TTD tersimpan
        $file_ttd = null;
        if (!empty($_FILES['file_ttd']['name'])) {
            $ext = strtolower(pathinfo($_FILES['file_ttd']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'])) {
                $filename = 'ttd_' . $id_pengajuan . '_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['file_ttd']['tmp_name'], $upload_ttd_dir . $filename);
                $file_ttd = $filename;

                // Update master TTD (hanya jika Kepala — sudah divalidasi sebelumnya)
                $petRow = getPetugasById($db, $id_petugas);
                if ($petRow && stripos($petRow['jabatan'] ?? '', 'kepala') !== false) {
                    $db->prepare("UPDATE petugas SET ttd = ? WHERE id = ?")
                        ->execute([$file_ttd, $id_petugas]);
                }
            }
        } elseif (!empty($_POST['ttd_filename'])) {
            $file_ttd = trim($_POST['ttd_filename']);
        }

        try {
            $stmt = $db->prepare(
                'INSERT INTO surat_bebas_pustaka (id_pengajuan, id_petugas, nomor_surat, file_pdf, file_ttd) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$id_pengajuan, $id_petugas, $nomor_surat, $file_pdf, $file_ttd]);
            respond(true, ['id' => $db->lastInsertId(), 'file_ttd' => $file_ttd], 'Surat bebas pustaka berhasil diterbitkan', 201);
        } catch (PDOException $e) {
            respond(false, null, 'Nomor surat sudah digunakan atau pengajuan sudah memiliki surat', 409);
        }
        break;

    case 'DELETE':
        if (!$id) respond(false, null, 'ID diperlukan', 400);
        $stmt = $db->prepare('DELETE FROM surat_bebas_pustaka WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) respond(false, null, 'Surat tidak ditemukan', 404);
        respond(true, null, 'Surat berhasil dihapus');
        break;

    default:
        respond(false, null, 'Method tidak didukung', 405);
}
