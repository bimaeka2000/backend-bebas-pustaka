<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

setCORSHeaders();
$db     = getDB();
$method = getMethod();
$id     = $_GET['id'] ?? null;
$nim    = $_GET['nim'] ?? null;

switch ($method) {
    case 'GET':
        if ($id) {
            $stmt = $db->prepare(
                'SELECT rp.*, m.nama, m.nim FROM riwayat_pinjaman rp
                 JOIN mahasiswa m ON m.nim = rp.nim
                 WHERE rp.id = ?'
            );
            $stmt->execute([$id]);
            $data = $stmt->fetch();
            if (!$data) respond(false, null, 'Data tidak ditemukan', 404);
            respond(true, $data);
        } elseif ($nim) {
            $stmt = $db->prepare(
                'SELECT rp.*, m.nama, m.email FROM riwayat_pinjaman rp
                 JOIN mahasiswa m ON m.nim = rp.nim
                 WHERE rp.nim = ?'
            );
            $stmt->execute([$nim]);
            respond(true, $stmt->fetchAll());
        } else {
            $stmt = $db->query(
                'SELECT rp.*, m.nama, m.email, j.nama_jurusan
                 FROM riwayat_pinjaman rp
                 JOIN mahasiswa m ON m.nim = rp.nim
                 JOIN jurusan j ON j.id = m.id_jurusan
                 ORDER BY rp.updated_at DESC'
            );
            respond(true, $stmt->fetchAll());
        }
        break;

    case 'PUT':
        // Update data pinjaman berdasarkan ID atau NIM
        $target_id  = $id ?? null;
        $target_nim = $nim ?? null;
        if (!$target_id && !$target_nim) respond(false, null, 'id atau nim diperlukan', 400);

        $body         = getRequestBody();
        $buku         = (int)($body['buku_dipinjam'] ?? 0);
        $tunggakan    = (float)($body['tunggakan_denda'] ?? 0);
        $bebas        = isset($body['status_bebas_tanggungan']) ? (bool)$body['status_bebas_tanggungan'] : false;

        if ($target_id) {
            $stmt = $db->prepare(
                'UPDATE riwayat_pinjaman SET buku_dipinjam=?, tunggakan_denda=?, status_bebas_tanggungan=? WHERE id=?'
            );
            $stmt->execute([$buku, $tunggakan, $bebas ? 1 : 0, $target_id]);
        } else {
            $cek = $db->prepare('SELECT id FROM riwayat_pinjaman WHERE nim = ?');
            $cek->execute([$target_nim]);
            if ($cek->fetch()) {
                $stmt = $db->prepare(
                    'UPDATE riwayat_pinjaman SET buku_dipinjam=?, tunggakan_denda=?, status_bebas_tanggungan=? WHERE nim=?'
                );
                $stmt->execute([$buku, $tunggakan, $bebas ? 1 : 0, $target_nim]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO riwayat_pinjaman (nim, buku_dipinjam, tunggakan_denda, status_bebas_tanggungan) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$target_nim, $buku, $tunggakan, $bebas ? 1 : 0]);
            }
        }
        respond(true, null, 'Riwayat pinjaman berhasil diupdate');
        break;

    default:
        respond(false, null, 'Method tidak didukung', 405);
}
