<?php

declare(strict_types=1);

spl_autoload_register(function ($class) {
    require __DIR__ . "/src/{$class}.php";
});

$parts = explode("/", $_SERVER['REQUEST_URI']);
$parts = array_filter($parts);
$parts = array_values($parts);

$route = null;
$id = null;

$agentIndex = array_search('agents', $parts);

if ($agentIndex !== false) {
    $route = $parts[$agentIndex];

    if (isset($parts[$agentIndex + 1])) {
        $id = $parts[$agentIndex + 1];
    }
} else {
    header("Content-Type: application/json");
    http_response_code(404);
    echo json_encode(["error" => "Resource not found"]);
    
    exit;
}

$controller = new AgentController();
$controller->processRequest($_SERVER['REQUEST_METHOD'], $id);
