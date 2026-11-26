<?php

use NodeService\CrmAiPhp\AuthorizationException;

require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        throw new AuthorizationException(405, 'Endpoint này chỉ nhận POST.');
    }
    $raw = file_get_contents('php://input', false, null, 0, 100001);
    $result = $widget->authorize($raw, $csrf, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (AuthorizationException $error) {
    http_response_code($error->status);
    echo json_encode(['error' => ['message' => $error->getMessage()]], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['error' => ['message' => 'Chưa thể cấp chữ ký CRM. Kiểm tra cấu hình và khóa ký ở backend.']]);
}
