<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/linebot.php'; 
require_once __DIR__ . '/../models/Member.php';
require_once __DIR__ . '/../models/Bill.php';
require_once __DIR__ . '/../models/Participant.php';
require_once __DIR__ . '/../services/MessageHandler.php';
require_once __DIR__ . '/../models/DbConnection.php';

header('Access-Control-Allow-Origin: https://liff.line.me');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit;
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGetRequest();
            break;
        case 'POST':
            handlePostRequest();
            break;
        default:
            http_response_code(405); 
            echo json_encode(['status' => 'error', 'message' => '不支援的請求方法']);
            break;
    }
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '伺服器內部錯誤，請稍後再試。']);
}

function handleGetRequest() {
    $action = $_GET['action'] ?? '';
    if ($action !== 'get_members') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '無效的操作']);
        return;
    }

    $groupId = $_GET['groupId'] ?? '';
    if (empty($groupId)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '缺少群組 ID']);
        return;
    }

    $db = DbConnection::getInstance();
    $memberUserIds = Member::getGroupMembers($db, $groupId);

    $membersWithProfile = [];
    foreach ($memberUserIds as $userId) {
        $profile = MessageHandler::getProfile($userId);

        if ($profile && isset($profile['displayName'])) {
            $membersWithProfile[] = [
                'userId' => $userId,
                'displayName' => $profile['displayName']
            ];
        } else {
            error_log("liff-api: Failed to get profile for userId: " . $userId);
        }
    }

    echo json_encode(['members' => $membersWithProfile]);
}

function handlePostRequest() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['groupId']) || empty($data['payerId']) || !isset($data['participants']) || empty($data['amount'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '缺少必要的帳單資料']);
        return;
    }

    $db = DbConnection::getInstance();
    $billId = Bill::createBill($db, $data);

    if ($billId) {
        echo json_encode(['status' => 'success', 'message' => '帳單已成功新增！', 'bill_id' => $billId]);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '新增帳單失敗']);
    }
}
