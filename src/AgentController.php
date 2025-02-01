<?php
require_once __DIR__ . "/../crest/crest.php";


class AgentController
{
    private $stageMappings = [
        'ALLOCATED' => "NEW",
        'INTERESTED' => "1",
        'NOT_INTERESTED' => "2",
        'CLOSED' => "3",
        'MEETING_BOOKED' => "4",
        'MEETING_DONE' => "5",
        'SEND_MESSAGE' => "UC_DHXASQ",
        'NO_ANSWER' => "PROCESSED",
        'INVALID_NUMBER' => "6",
        'NEVER_ANSWERED' => "7",
        'CALL_BACK' => "8",
        'LATE_FOLLOWUP' => "9",
        'TODAY_FOLLOWUP' => "10",
        'FUTURE_FOLLOWUP' => "11",
    ];

    public function processRequest(string $method, ?string $id): void
    {
        if ($id) {
            $this->processResourceRequest($method, $id);
        } else {
            $this->processCollectionRequest($method);
        }
    }

    private function processResourceRequest(string $method, ?string $id): void
    {
        if ($method === 'GET' && $id) {
            $userResponse = CRest::call('user.get', [
                'filter' => ['ID' => $id],
                'select' => ['ID', 'NAME', 'LAST_NAME']
            ]);

            if (empty($userResponse['result'])) {
                echo json_encode(['error' => 'User not found']);
                return;
            }

            $user = $userResponse['result'][0];
            $userName = trim("{$user['NAME']} {$user['LAST_NAME']}");

            $batchCommands = [];

            foreach ($this->stageMappings as $stage => $statusId) {
                $batchCommands[strtolower($stage)] =
                    "crm.lead.list?filter[STATUS_ID]={$statusId}&filter[ASSIGNED_BY_ID]={$id}&select[]=ID";
            }

            $response = CRest::call('batch', ['cmd' => $batchCommands]);

            $leadCounts = ['name' => $userName];
            foreach ($this->stageMappings as $stage => $statusId) {
                $stageKey = strtolower($stage);
                $leadCounts[$stageKey] = isset($response['result']['result'][$stageKey])
                    ? count($response['result']['result'][$stageKey])
                    : 0;
            }
            header("Content-Type: application/json");
            echo json_encode($leadCounts);

            exit;
        } else {
            header("Content-Type: application/json");
            http_response_code(404);
            echo json_encode(["error" => "Resource not found"]);
            
            exit;
        }
    }


    private function processCollectionRequest(string $method): void
    {
        if ($method === 'GET') {
            $usersResponse = CRest::call('user.get', [
                'filter' => ['ACTIVE' => true],
                'select' => ['ID', 'NAME', 'LAST_NAME']
            ]);

            if (empty($usersResponse['result'])) {
                echo json_encode(['error' => 'No users found']);
                return;
            }

            $users = $usersResponse['result'];
            $batchCommands = [];

            foreach ($users as $user) {
                $userId = $user['ID'];
                $userKey = "user_{$userId}";

                foreach ($this->stageMappings as $stage => $statusId) {
                    $batchCommands["{$userKey}_" . strtolower($stage)] =
                        "crm.lead.list?filter[STATUS_ID]={$statusId}&filter[ASSIGNED_BY_ID]={$userId}&select[]=ID";
                }
            }

            $response = CRest::call('batch', ['cmd' => $batchCommands]);

            $userLeadCounts = [];
            foreach ($users as $user) {
                $userId = $user['ID'];
                $userKey = "user_{$userId}";
                $userName = trim("{$user['NAME']} {$user['LAST_NAME']}");

                foreach ($this->stageMappings as $stage => $statusId) {
                    $stageKey = "{$userKey}_" . strtolower($stage);
                    $userLeadCounts[$userId][strtolower($stage)] =
                        isset($response['result']['result'][$stageKey])
                        ? count($response['result']['result'][$stageKey])
                        : 0;
                    $userLeadCounts[$userId]['name'] = $userName;
                }
            }

            header("Content-Type: application/json");
            echo json_encode($userLeadCounts);

            exit;
        } else {
            header("Content-Type: application/json");
            http_response_code(404);
            echo json_encode(["error" => "Resource not found"]);

            exit;
        }
    }
}
