<?php

declare(strict_types=1);

spl_autoload_register(function ($class) {
    require __DIR__ . "/src/{$class}.php";
});

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts = explode("/", $uri);
$parts = array_filter($parts);
$parts = array_values($parts);

$route = null;
$id = null;
$teamId = null;
$dateFrom = null;
$dateTo = null;

$agentIndex = array_search('agents', $parts);
$teamIndex = array_search('teams', $parts);
$leadIndex = array_search('leads', $parts);

if ($agentIndex !== false) {
    $route = 'agents';
    $id = $parts[$agentIndex + 1] ?? null;
    $teamId = $_GET['team'] ?? null;
    $dateFrom = $_GET['datefrom'] ?? null;
    $dateTo = $_GET['dateto'] ?? null;

    $controller = new AgentController();
    $controller->processRequest($_SERVER['REQUEST_METHOD'], $id, $teamId, $dateFrom, $dateTo);
} elseif ($teamIndex !== false) {
    $route = 'teams';
    $id = $parts[$teamIndex + 1] ?? null;

    $controller = new TeamController();
    $controller->processRequest($_SERVER['REQUEST_METHOD'], $id);
} elseif ($leadIndex !== false) {
    $route = 'leads';
    $id = $parts[$leadIndex + 1] ?? null;
    $teamId = $_GET['team'] ?? null;
    $dateFrom = $_GET['datefrom'] ?? null;
    $dateTo = $_GET['dateto'] ?? null;

    $controller = new LeadController();
    $controller->processRequest($_SERVER['REQUEST_METHOD'], $id, $teamId, $dateFrom, $dateTo);
} else {
    header("Content-Type: application/json");
    http_response_code(404);
    echo json_encode(["error" => "Resource not found"]);
    exit;
}

exit;
