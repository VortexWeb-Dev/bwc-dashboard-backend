<?php
require_once __DIR__ . "/../crest/crest.php";

class AgentController
{
    private $stageMappings = [
        'ALLOCATED' => "NEW",
        'NOT_INTERESTED' => 2,
        'CLOSED' => 3,
        'MEETING_BOOKED' => 4,
        'MEETING_DONE' => 5,
        'NO_ANSWER' => "PROCESSED",
        'INVALID_NUMBER' => 6,
        'NEVER_ANSWERED' => 7,
        'CALL_BACK' => 8,
        // 'INTERESTED' => 1,
        // 'SEND_MESSAGE' => "UC_DHXASQ",
        // 'LATE_FOLLOWUP' => 9,
        // 'TODAY_FOLLOWUP' => 10,
        // 'FUTURE_FOLLOWUP' => 11,
    ];

    private $maxBatchCommands = 50;
    private $cacheExpiry = 300;

    public function processRequest(string $method, ?string $id, ?string $teamId, ?string $dateFrom, ?string $dateTo): void
    {
        if ($method !== 'GET') {
            $this->sendErrorResponse(405, "Method not allowed");
            return;
        }

        if ($id) {
            $this->processResourceRequest($id, $dateFrom, $dateTo);
            return;
        }

        if ($teamId) {
            $this->processTeamRequest($teamId, $dateFrom, $dateTo);
            return;
        }

        $this->processCollectionRequest($dateFrom, $dateTo);
    }

    private function processResourceRequest(string $id, ?string $dateFrom, ?string $dateTo): void
    {
        $cacheKey = "user_reports_{$id}";
        if ($dateFrom && $dateTo) {
            $cacheKey .= "_{$dateFrom}_{$dateTo}";
        }
        $cachedData = $this->getCache($cacheKey);

        if ($cachedData !== false) {
            $this->sendJsonResponse($cachedData);
            return;
        }

        $userResponse = CRest::call('user.get', [
            'filter' => ['ID' => $id],
            'select' => ['ID', 'NAME', 'LAST_NAME']
        ]);

        if (empty($userResponse['result'])) {
            $this->sendErrorResponse(404, "User not found");
            return;
        }

        $user = $userResponse['result'][0];
        $userName = trim("{$user['NAME']} {$user['LAST_NAME']}");

        $response = $this->fetchUserLeads($id, $dateFrom, $dateTo);

        $leadCounts = ['name' => $userName];
        foreach ($this->stageMappings as $stage => $statusId) {
            $stageKey = strtolower($stage);
            $leadCounts[$stageKey] = isset($response[$stageKey]) ? count($response[$stageKey]) : 0;
        }

        $this->setCache($cacheKey, $leadCounts);
        $this->sendJsonResponse($leadCounts);
    }

    private function processCollectionRequest(?string $dateFrom, ?string $dateTo): void
    {
        $cacheKey = "all_user_reports";
        if ($dateFrom && $dateTo) {
            $cacheKey .= "_{$dateFrom}_{$dateTo}";
        }
        $cachedData = $this->getCache($cacheKey);

        if ($cachedData !== false) {
            $this->sendJsonResponse($cachedData);
            return;
        }

        $usersResponse = CRest::call('user.get', [
            'filter' => ['ACTIVE' => true, '!=ID' => [9, 11]],
            'select' => ['ID', 'NAME', 'LAST_NAME']
        ]);

        if (empty($usersResponse['result'])) {
            $this->sendErrorResponse(404, "No users found");
            return;
        }

        $users = $usersResponse['result'];
        $userLeadCounts = [];

        $userBatches = array_chunk($users, 5);

        foreach ($userBatches as $userBatch) {
            $userPromises = [];

            foreach ($userBatch as $user) {
                $userId = $user['ID'];
                $userName = trim("{$user['NAME']} {$user['LAST_NAME']}");

                $leadCounts = $this->fetchUserLeads($userId, $dateFrom, $dateTo);

                $userLeadCounts[$userId] = ['name' => $userName];
                foreach ($this->stageMappings as $stage => $statusId) {
                    $stageKey = strtolower($stage);
                    $userLeadCounts[$userId][$stageKey] = isset($leadCounts[$stageKey]) ? count($leadCounts[$stageKey]) : 0;
                }
            }

            usleep(200000);
        }

        $this->setCache($cacheKey, $userLeadCounts);
        $this->sendJsonResponse($userLeadCounts);
    }

    private function processTeamRequest(string $teamId, ?string $dateFrom, ?string $dateTo): void
    {
        $teamResponse = CRest::call('department.get', ['ID' => $teamId]);

        if (empty($teamResponse['result'])) {
            $this->sendErrorResponse(404, "Invalid team ID");
            return;
        }

        $teamName = $teamResponse['result'][0]['NAME'] ?? "Unknown Team";
        $cacheKey = "team_reports_{$teamId}";
        if ($dateFrom && $dateTo) {
            $cacheKey .= "_{$dateFrom}_{$dateTo}";
        }
        $cachedData = $this->getCache($cacheKey);

        if ($cachedData !== false) {
            $this->sendJsonResponse($cachedData);
            return;
        }

        $usersResponse = CRest::call('user.get', [
            'filter' => [
                'ACTIVE' => true,
                'UF_DEPARTMENT' => $teamId,
                '!=ID' => [9, 11]
            ],
            'select' => ['ID', 'NAME', 'LAST_NAME', 'UF_DEPARTMENT']
        ]);

        if (empty($usersResponse['result'])) {
            $this->sendErrorResponse(404, "No users found in this team");
            return;
        }

        $users = $usersResponse['result'];
        $userLeadCounts = [];
        $userBatches = array_chunk($users, 5);

        foreach ($userBatches as $userBatch) {
            foreach ($userBatch as $user) {
                $userId = $user['ID'];
                $userName = trim("{$user['NAME']} {$user['LAST_NAME']}");

                $leadCounts = $this->fetchUserLeads($userId, $dateFrom, $dateTo);

                $userLeadCounts[$userId] = ['name' => $userName];
                foreach ($this->stageMappings as $stage => $statusId) {
                    $stageKey = strtolower($stage);
                    $userLeadCounts[$userId][$stageKey] = isset($leadCounts[$stageKey]) ? count($leadCounts[$stageKey]) : 0;
                }
            }

            usleep(200000);
        }

        $teamTotals = ['name' => 'Team Total'];
        foreach ($this->stageMappings as $stage => $statusId) {
            $stageKey = strtolower($stage);
            $teamTotals[$stageKey] = 0;

            foreach ($userLeadCounts as $userData) {
                $teamTotals[$stageKey] += $userData[$stageKey];
            }
        }

        $result = [
            'team_id' => $teamId,
            'team_name' => $teamName,
            'team_total' => $teamTotals,
            'members' => $userLeadCounts
        ];

        $this->setCache($cacheKey, $result);
        $this->sendJsonResponse($result);
    }

    private function fetchUserLeads(string $userId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $batchCommands = [];
        $batchResults = [];
        $batchCount = 0;

        foreach ($this->stageMappings as $stage => $statusId) {
            $stageKey = strtolower($stage);

            $filter = [
                'STATUS_ID' => $statusId,
                'ASSIGNED_BY_ID' => $userId
            ];

            if ($dateFrom) {
                $filter['>DATE_CREATE'] = $dateFrom;
            }

            if ($dateTo) {
                $filter['<DATE_CREATE'] = $dateTo;
            }

            $filterQueryString = http_build_query(['filter' => $filter]);
            $filterQueryString = str_replace(['%5B', '%5D'], ['[', ']'], $filterQueryString);

            $batchCommands[$stageKey] = "crm.lead.list?{$filterQueryString}&select[]=ID";
            $batchCount++;

            if ($batchCount >= $this->maxBatchCommands) {
                $response = CRest::call('batch', ['cmd' => $batchCommands, 'halt' => 0]);
                if (isset($response['result']['result'])) {
                    $batchResults = array_merge($batchResults, $response['result']['result']);
                }
                $batchCommands = [];
                $batchCount = 0;
            }
        }

        if (!empty($batchCommands)) {
            $response = CRest::call('batch', ['cmd' => $batchCommands, 'halt' => 0]);
            if (isset($response['result']['result'])) {
                $batchResults = array_merge($batchResults, $response['result']['result']);
            }
        }

        return $batchResults;
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
