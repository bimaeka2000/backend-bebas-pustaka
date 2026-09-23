<?php
// ============================================================
// API: Activity Logs (Superadmin & Admin)
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

setCORSHeaders();
$db     = getDB();
$method = getMethod();

// Pastikan tabel activity_logs tersedia
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS activity_logs (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            id_petugas  INT          NULL,
            nama_petugas VARCHAR(150) NULL,
            role        VARCHAR(30)  NOT NULL DEFAULT 'admin',
            aksi        VARCHAR(100) NOT NULL,
            detail      TEXT         NULL,
            ip_address  VARCHAR(50)  NULL,
            user_agent  VARCHAR(255) NULL,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_role        (role),
            INDEX idx_created_at  (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

switch ($method) {
    case 'GET':
        $action      = $_GET['action'] ?? 'list';
        $limit       = min((int)($_GET['limit']  ?? 20), 100);
        $role_filter = $_GET['role']   ?? '';
        $page        = max(1, (int)($_GET['page'] ?? 1));
        $offset      = ($page - 1) * $limit;

        $where  = '1=1';
        $params = [];
        if ($role_filter) {
            $where   .= ' AND al.role = ?';
            $params[] = $role_filter;
        }

        if ($action === 'stats') {
            $total = $db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
            $today = $db->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
            $sa    = $db->query("SELECT COUNT(*) FROM activity_logs WHERE role = 'superadmin'")->fetchColumn();
            $adm   = $db->query("SELECT COUNT(*) FROM activity_logs WHERE role = 'admin'")->fetchColumn();
            respond(true, [
                'total_logs'      => (int)$total,
                'login_hari_ini'  => (int)$today,
                'aksi_superadmin' => (int)$sa,
                'aksi_admin'      => (int)$adm,
            ]);
        }

        $stmtCount = $db->prepare("SELECT COUNT(*) FROM activity_logs al WHERE $where");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        $stmt = $db->prepare("
            SELECT al.id, al.id_petugas, al.nama_petugas, al.role,
                   al.aksi, al.detail, al.ip_address, al.created_at
            FROM activity_logs al
            WHERE $where
            ORDER BY al.created_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        respond(true, [
            'logs'        => $logs,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ]);
        break;

    case 'POST':
        $body       = getRequestBody();
        $id_petugas = $body['id_petugas']   ?? null;
        $nama       = trim($body['nama_petugas'] ?? '');
        $role       = trim($body['role']     ?? 'admin');
        $aksi       = trim($body['aksi']     ?? '');
        $detail     = trim($body['detail']   ?? '');
        $ip         = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua         = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        if (!$aksi) respond(false, null, 'Aksi wajib diisi', 400);

        $stmt = $db->prepare("
            INSERT INTO activity_logs (id_petugas, nama_petugas, role, aksi, detail, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$id_petugas ?: null, $nama ?: null, $role, $aksi, $detail ?: null, $ip, $ua]);
        respond(true, ['id' => $db->lastInsertId()], 'Log berhasil dicatat', 201);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) respond(false, null, 'ID log tidak ditemukan', 400);
        $db->prepare('DELETE FROM activity_logs WHERE id = ?')->execute([$id]);
        respond(true, null, 'Log berhasil dihapus');
        break;

    default:
        respond(false, null, 'Method tidak didukung', 405);
}
