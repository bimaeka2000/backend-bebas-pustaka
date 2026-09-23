<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

setCORSHeaders();
$db     = getDB();
$method = getMethod();
$id     = $_GET['id'] ?? null;

switch ($method) {
    // ── GET: ambil semua atau satu jurusan ──────────────────────
    case 'GET':
        if ($id) {
            $stmt = $db->prepare('SELECT j.*, COUNT(m.nim) AS jumlah_mahasiswa
                                  FROM jurusan j
                                  LEFT JOIN mahasiswa m ON m.id_jurusan = j.id
                                  WHERE j.id = ?
                                  GROUP BY j.id');
            $stmt->execute([$id]);
            $data = $stmt->fetch();
            if (!$data) respond(false, null, 'Jurusan tidak ditemukan', 404);
            respond(true, $data);
        } else {
            $stmt = $db->query('SELECT j.*, COUNT(m.nim) AS jumlah_mahasiswa
                                FROM jurusan j
                                LEFT JOIN mahasiswa m ON m.id_jurusan = j.id
                                GROUP BY j.id
                                ORDER BY j.nama_jurusan');
            respond(true, $stmt->fetchAll());
        }
        break;

    // ── POST: tambah jurusan ────────────────────────────────────
    case 'POST':
        $body = getRequestBody();
        $nama = trim($body['nama_jurusan'] ?? '');
        $fak  = trim($body['fakultas'] ?? '');
        if (!$nama || !$fak) respond(false, null, 'nama_jurusan dan fakultas wajib diisi', 400);
        $stmt = $db->prepare('INSERT INTO jurusan (nama_jurusan, fakultas) VALUES (?, ?)');
        $stmt->execute([$nama, $fak]);
        respond(true, ['id' => $db->lastInsertId()], 'Jurusan berhasil ditambahkan', 201);
        break;

    // ── PUT: update jurusan ─────────────────────────────────────
    case 'PUT':
        if (!$id) respond(false, null, 'ID jurusan diperlukan', 400);
        $body = getRequestBody();
        $nama = trim($body['nama_jurusan'] ?? '');
        $fak  = trim($body['fakultas'] ?? '');
        if (!$nama || !$fak) respond(false, null, 'nama_jurusan dan fakultas wajib diisi', 400);
        $stmt = $db->prepare('UPDATE jurusan SET nama_jurusan = ?, fakultas = ? WHERE id = ?');
        $stmt->execute([$nama, $fak, $id]);
        if ($stmt->rowCount() === 0) respond(false, null, 'Jurusan tidak ditemukan', 404);
        respond(true, null, 'Jurusan berhasil diupdate');
        break;

    // ── DELETE: hapus jurusan ───────────────────────────────────
    case 'DELETE':
        if (!$id) respond(false, null, 'ID jurusan diperlukan', 400);
        try {
            $stmt = $db->prepare('DELETE FROM jurusan WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) respond(false, null, 'Jurusan tidak ditemukan', 404);
            respond(true, null, 'Jurusan berhasil dihapus');
        } catch (PDOException $e) {
            respond(false, null, 'Tidak dapat menghapus jurusan yang masih memiliki mahasiswa', 409);
        }
        break;

    default:
        respond(false, null, 'Method tidak didukung', 405);
}
