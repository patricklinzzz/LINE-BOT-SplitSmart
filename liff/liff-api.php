<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/linebot.php'; 
require_once __DIR__ . '/../models/Member.php';
require_once __DIR__ . '/../models/Bill.php';
require_once __DIR__ . '/../models/Participant.php';
require_once __DIR__ . '/../services/MessageHandler.php';
require_once __DIR__ . '/../models/DbConnection.php';
require_once __DIR__ . '/../services/BillService.php';

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
    $groupId = $_GET['groupId'] ?? '';
    if (empty($groupId)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '缺少群組 ID']);
        return;
    }
    $db = DbConnection::getInstance();

    switch ($action) {
        case 'get_members':
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
            break;

        case 'get_bills':
            $bills = BillService::getBillsForGroup($db, $groupId);
            echo json_encode(['bills' => $bills]);
            break;

        case 'get_bill_details':
            $billId = $_GET['billId'] ?? '';
            if (empty($billId)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => '缺少帳單 ID']);
                return;
            }
            $billDetails = BillService::getBillDetails($db, $billId);
            if ($billDetails) {
                echo json_encode(['bill' => $billDetails]);
            } else {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => '找不到指定的帳單']);
            }
            break;

        case 'get_balance_report_liff':
            $balances = BillService::getFinalBalance($db, $groupId);
            if (empty($balances)) {
                echo json_encode(['balances' => [], 'transactions' => []]);
                return;
            }
            $transactions = BillService::calculateSettlementTransactions($balances);

            $userIds = array_keys($balances);
            foreach ($transactions as $t) {
                $userIds[] = $t['from'];
                $userIds[] = $t['to'];
            }
            $userIds = array_unique($userIds);

            $profiles = BillService::getProfilesForUserIds($userIds);

            $formattedBalances = [];
            foreach ($balances as $userId => $balance) {
                if (abs($balance) > 0.01) {
                    $formattedBalances[] = [
                        'userName' => $profiles[$userId]['displayName'] ?? '未知用戶',
                        'amount' => $balance,
                    ];
                }
            }
            usort($formattedBalances, fn($a, $b) => $b['amount'] <=> $a['amount']);

            $formattedTransactions = [];
            foreach ($transactions as $t) {
                $formattedTransactions[] = [
                    'from' => $profiles[$t['from']]['displayName'] ?? '未知用戶',
                    'to' => $profiles[$t['to']]['displayName'] ?? '未知用戶',
                    'amount' => $t['amount'],
                ];
            }

            echo json_encode(['balances' => $formattedBalances, 'transactions' => $formattedTransactions]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => '無效的 GET 操作']);
            break;
    }
}

function handlePostRequest() {
    $data = json_decode(file_get_contents('php://input'), true);
    $db = DbConnection::getInstance();
    $action = $data['action'];

    switch ($action) {
        case 'add_bill':
            if (empty($data['groupId']) || empty($data['payerId']) || !isset($data['participants']) || empty($data['amount'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => '缺少必要的帳單資料']);
                return;
            }
            $billId = Bill::createBill($db, $data);
            if ($billId) {
                $flexMessage = BillService::createBillSummaryFlexMessage($db, $billId, false);
                MessageHandler::sendPushMessage($data['groupId'], $flexMessage);
                echo json_encode(['status' => 'success', 'message' => '帳單已成功新增！', 'bill_id' => $billId]);
            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => '新增帳單失敗']);
            }
            break;

        case 'update_bill':
            if (empty($data['billId']) || empty($data['groupId']) || empty($data['payerId']) || !isset($data['participants']) || empty($data['amount'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => '缺少必要的帳單更新資料']);
                return;
            }
            $success = Bill::updateBill($db, $data);
            if ($success) {
                $flexMessage = BillService::createBillSummaryFlexMessage($db, $data['billId'], true);
                MessageHandler::sendPushMessage($data['groupId'], $flexMessage);
                echo json_encode(['status' => 'success', 'message' => '帳單已成功更新！']);
            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => '更新帳單失敗']);
            }
            break;

        case 'delete_bill':
            if (empty($data['bill_id'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => '缺少帳單 ID']);
                return;
            }
            $success = Bill::deleteBill($db, $data['bill_id'],$data['deleter']);
            if ($success) {
                echo json_encode(['status' => 'success', 'message' => '帳單已刪除']);
            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => '刪除帳單失敗']);
            }
            break;

        case 'settle_bills_liff':
            if (empty($data['groupId'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => '缺少群組 ID']);
                return;
            }
            // BillService::settleBills returns the number of affected rows.
            $affectedRows = BillService::settleBills($db, $data['groupId']);
            
            if ($affectedRows >= 0) {
                echo json_encode(['status' => 'success', 'message' => '帳單已成功結算']);
            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => '結算帳單失敗']);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => '無效的 POST 操作']);
            break;
    }
}
