<?php
// ============================================================
// Helpers: CORS, JSON Response, Request Parsing
// ============================================================

function setCORSHeaders(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Content-Type: application/json; charset=UTF-8');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

function respond(bool $success, $data = null, string $message = '', int $code = 200, array $extra = []): void {
    http_response_code($code);
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    foreach ($extra as $key => $val) {
        $response[$key] = $val;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function getRequestBody(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json) && !empty($json)) {
        return $json;
    }
    return !empty($_POST) ? $_POST : (is_array($json) ? $json : []);
}

function getMethod(): string {
    return $_SERVER['REQUEST_METHOD'];
}
