<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

setCORSHeaders();
$db     = getDB();
$method = getMethod();
$nim    = $_GET['nim']          ?? null;
$id     = $_GET['id']           ?? null;
$pengajuan_id = $_GET['pengajuan_id'] ?? null;

switch ($method) {
    // ── GET: ambil notifikasi by NIM (untuk portal mahasiswa) ──
    case 'GET':
        if ($nim) {
            $stmt = $db->prepare(
                'SELECT n.*, p.nama AS nama_pengirim
                 FROM notifikasi_mahasiswa n
                 LEFT JOIN petugas p ON p.id = n.id_petugas
                 WHERE n.nim = ?
                 ORDER BY n.created_at DESC'
            );
            $stmt->execute([$nim]);
            respond(true, $stmt->fetchAll());
        } elseif ($pengajuan_id) {
            $stmt = $db->prepare(
                'SELECT n.*, p.nama AS nama_pengirim
                 FROM notifikasi_mahasiswa n
                 LEFT JOIN petugas p ON p.id = n.id_petugas
                 WHERE n.id_pengajuan = ?
                 ORDER BY n.created_at DESC'
            );
            $stmt->execute([$pengajuan_id]);
            respond(true, $stmt->fetchAll());
        } else {
            $stmt = $db->prepare(
                'SELECT n.*, m.nama AS nama_mahasiswa, p.nama AS nama_pengirim
                 FROM notifikasi_mahasiswa n
                 LEFT JOIN mahasiswa m ON m.nim = n.nim
                 LEFT JOIN petugas p ON p.id = n.id_petugas
                 ORDER BY n.created_at DESC'
            );
            $stmt->execute();
            respond(true, $stmt->fetchAll());
        }
        break;

    // ── POST: kirim pesan baru ke mahasiswa ──
    case 'POST':
        $body = getRequestBody();
        $required = ['nim', 'pesan', 'jenis'];
        foreach ($required as $f) {
            if (empty($body[$f])) respond(false, null, "Field '$f' wajib diisi", 422);
        }

        $jenis_allowed = ['info', 'warning', 'success', 'error'];
        if (!in_array($body['jenis'], $jenis_allowed)) {
            respond(false, null, 'Jenis tidak valid (info/warning/success/error)', 422);
        }

        $stmt = $db->prepare(
            'INSERT INTO notifikasi_mahasiswa
             (nim, id_pengajuan, id_petugas, pesan, jenis, judul)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $body['nim'],
            $body['id_pengajuan'] ?? null,
            $body['id_petugas']   ?? null,
            $body['pesan'],
            $body['jenis'],
            $body['judul']        ?? null,
        ]);
        respond(true, ['id' => $db->lastInsertId()], 'Pesan berhasil dikirim', 201);
        break;

    // ── PUT: tandai sudah dibaca ──
    case 'PUT':
        if (!$id) respond(false, null, 'ID diperlukan', 400);
        $stmt = $db->prepare('UPDATE notifikasi_mahasiswa SET sudah_dibaca = 1 WHERE id = ?');
        $stmt->execute([$id]);
        respond(true, null, 'Ditandai sudah dibaca');
        break;

    // ── DELETE ──
    case 'DELETE':
        if (!$id) respond(false, null, 'ID diperlukan', 400);
        $stmt = $db->prepare('DELETE FROM notifikasi_mahasiswa WHERE id = ?');
        $stmt->execute([$id]);
        respond(true, null, 'Notifikasi dihapus');
        break;

    default:
        respond(false, null, 'Method tidak diizinkan', 405);
}
