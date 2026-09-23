<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/bootstrap.php';
\App\Security\Session::start();
function json_response(array $payload, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store'); echo json_encode($payload, JSON_UNESCAPED_UNICODE); exit; }
