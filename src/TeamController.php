<?php
require_once __DIR__ . "/../crest/crest.php";

class TeamController
{
    private int $cacheExpiry = 300;

    public function processRequest(): void
    {
        $this->processCollectionRequest();
    }

    private function processCollectionRequest(): void
    {
        $cacheKey = "bitrix_departments";
        $cachedData = $this->getCache($cacheKey);

        if ($cachedData) {
            $this->sendJsonResponse($cachedData);
        }

        $response = CRest::call("department.get");

        if (isset($response['result'])) {
            $this->setCache($cacheKey, $response['result']);
            $this->sendJsonResponse($response['result']);
        } else {
            $this->sendErrorResponse(500, "Failed to fetch departments");
        }
    }

    private function getCache(string $key)
    {
        $cacheFile = sys_get_temp_dir() . "/bitrix_" . md5($key) . ".cache";

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $this->cacheExpiry)) {
            return json_decode(file_get_contents($cacheFile), true);
        }

        return false;
    }

    private function setCache(string $key, $data): void
    {
        $cacheFile = sys_get_temp_dir() . "/bitrix_" . md5($key) . ".cache";
        file_put_contents($cacheFile, json_encode($data));
    }

    private function sendJsonResponse($data): void
    {
        header("Content-Type: application/json");
        header("Cache-Control: max-age=300, public");
        echo json_encode($data);
        exit;
    }

    private function sendErrorResponse(int $code, string $message): void
    {
        header("Content-Type: application/json");
        http_response_code($code);
        echo json_encode(["error" => $message]);
        exit;
    }
}
