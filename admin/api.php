<?php
session_start();

require_once __DIR__ . '/../models/DbConnection.php';
require_once __DIR__ . '/../services/BillService.php';
require_once __DIR__ . '/../services/MessageHandler.php';

header('Content-Type: application/json');

function checkAdminLogin()
{
  if (!isset($_SESSION['is_admin_logged_in']) || $_SESSION['is_admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => '未授權，請先登入']);
    exit;
  }
}

function handleLogin()
{
  $data = json_decode(file_get_contents('php://input'), true);
  if (!isset($data['username']) || !isset($data['password'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => '請輸入帳號和密碼']);
    return;
  }

  $db = DbConnection::getInstance();
  $stmt = $db->prepare("SELECT password_hash FROM admins WHERE username = ?");
  $stmt->bind_param("s", $data['username']);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($admin = $result->fetch_assoc()) {
    if (password_verify($data['password'], $admin['password_hash'])) {
      $_SESSION['is_admin_logged_in'] = true;
      echo json_encode(['status' => 'success', 'message' => '登入成功']);
      return;
    }
  }

  // If username not found or password incorrect
  http_response_code(401);
  echo json_encode(['status' => 'error', 'message' => '帳號或密碼錯誤']);
}

function handleGetWhitelistSettings($db)
{
  $settings_res = $db->query("SELECT setting_value FROM bot_settings WHERE setting_key = 'whitelist_enabled'");
  if (!$settings_res) {
    // Handle case where settings table might be empty or query fails
    $whitelist_enabled = false;
  } else {
    $setting_row = $settings_res->fetch_assoc();
    $whitelist_enabled = $setting_row ? $setting_row['setting_value'] === 'true' : false;
  }

  // Get all groups with bills (active groups)
  $active_groups_res = $db->query("SELECT DISTINCT group_id FROM bills");
  $active_group_ids = [];
  while ($row = $active_groups_res->fetch_assoc()) {
    $active_group_ids[] = $row['group_id'];
  }

  // Get all groups already in the whitelist table
  $whitelisted_groups_res = $db->query("SELECT group_id FROM group_whitelist");
  $whitelisted_group_ids = [];
  while ($row = $whitelisted_groups_res->fetch_assoc()) {
    $whitelisted_group_ids[] = $row['group_id'];
  }

  // Find the difference to identify active but unregistered groups
  $unregistered_active_ids = array_diff($active_group_ids, $whitelisted_group_ids);
  $unregistered_groups = [];
  foreach ($unregistered_active_ids as $id) {
    $unregistered_groups[] = ['group_id' => $id];
  }

  $groups_res = $db->query("SELECT group_id, status, created_at FROM group_whitelist ORDER BY created_at DESC");
  $groups = [
    'pending' => [],
    'approved' => [],
    'denied' => []
  ];
  while ($row = $groups_res->fetch_assoc()) {
    if (array_key_exists($row['status'], $groups)) {
      $groups[$row['status']][] = $row;
    }
  }

  echo json_encode([
    'status' => 'success',
    'data' => [
      'enabled' => $whitelist_enabled,
      'groups' => $groups,
      'unregistered_active' => $unregistered_groups
    ]
  ]);
}

function handleUpdateWhitelistToggle($db)
{
  $data = json_decode(file_get_contents('php://input'), true);
  $enabled = $data['enabled'] ? 'true' : 'false';

  $stmt = $db->prepare("INSERT INTO bot_settings (setting_key, setting_value) VALUES ('whitelist_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
  $stmt->bind_param("ss", $enabled, $enabled);
  $stmt->execute();

  echo json_encode(['status' => 'success', 'message' => '設定已更新']);
}

function handleUpdateGroupStatus($db)
{
  $data = json_decode(file_get_contents('php://input'), true);
  $groupId = $data['groupId'];
  $status = $data['status'];

  // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both new and existing entries
  $stmt = $db->prepare("INSERT INTO group_whitelist (group_id, status) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = ?");
  $stmt->bind_param("sss", $groupId, $status, $status);
  $stmt->execute();

  if ($status === 'denied') {
    MessageHandler::leaveGroup($groupId);
  }

  echo json_encode(['status' => 'success', 'message' => '群組狀態已更新']);
}

function handleGetAllBills()
{
  $db = DbConnection::getInstance();

  // 一次性查詢所有帳單及其參與者
  $sql = "SELECT 
                b.group_id, 
                b.bill_id, 
                b.bill_name, 
                b.total_amount, 
                b.payer_user_id, 
                p.user_id AS participant_user_id,
                b.is_settled,
                b.created_at
            FROM bills b
            LEFT JOIN bill_participants p ON b.bill_id = p.bill_id
            ORDER BY b.group_id, b.created_at DESC";

  $result = $db->query($sql);

  $billsData = [];
  $userIds = [];

  while ($row = $result->fetch_assoc()) {
    $billId = $row['bill_id'];
    $groupId = $row['group_id'];

    if (!isset($billsData[$groupId])) {
      $billsData[$groupId] = [];
    }

    if (!isset($billsData[$groupId][$billId])) {
      $billsData[$groupId][$billId] = [
        'bill_id' => $billId,
        'bill_name' => $row['bill_name'],
        'total_amount' => $row['total_amount'],
        'payer_user_id' => $row['payer_user_id'],
        'is_settled' => (bool)$row['is_settled'],
        'created_at' => $row['created_at'],
        'participants_user_ids' => []
      ];
      $userIds[$row['payer_user_id']] = true;
    }

    if ($row['participant_user_id']) {
      $billsData[$groupId][$billId]['participants_user_ids'][] = $row['participant_user_id'];
      $userIds[$row['participant_user_id']] = true;
    }
  }

  // 批次獲取所有使用者名稱
  $profiles = BillService::getProfilesForUserIds(array_keys($userIds));

  // 整理成最終的回應格式
  $response = [];
  foreach ($billsData as $groupId => $bills) {
    $groupBills = [];
    foreach ($bills as $bill) {
      $participantNames = [];
      foreach ($bill['participants_user_ids'] as $pId) {
        $participantNames[] = $profiles[$pId]['displayName'] ?? '未知用戶';
      }

      $groupBills[] = [
        'bill_id' => $bill['bill_id'],
        'bill_name' => $bill['bill_name'],
        'total_amount' => $bill['total_amount'],
        'payer_name' => $profiles[$bill['payer_user_id']]['displayName'] ?? '未知用戶',
        'participants_names' => $participantNames,
        'is_settled' => $bill['is_settled'],
        'created_at' => $bill['created_at'],
      ];
    }
    $response[] = ['groupId' => $groupId, 'bills' => $groupBills];
  }

  echo json_encode(['data' => $response]);
}

try {
  $action = $_REQUEST['action'] ?? '';
  $db = DbConnection::getInstance();

  switch ($action) {
    case 'login':
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handleLogin();
      }
      break;
    case 'logout':
      session_unset();
      session_destroy();
      echo json_encode(['status' => 'success', 'message' => '已登出']);
      break;
    case 'check_login':
      echo json_encode(['loggedIn' => isset($_SESSION['is_admin_logged_in']) && $_SESSION['is_admin_logged_in'] === true]);
      break;
    case 'get_whitelist_settings':
      checkAdminLogin();
      handleGetWhitelistSettings($db);
      break;
    case 'update_whitelist_toggle':
      checkAdminLogin();
      handleUpdateWhitelistToggle($db);
      break;
    case 'update_group_status':
      checkAdminLogin();
      handleUpdateGroupStatus($db);
      break;
    case 'get_all_bills':
      checkAdminLogin(); // Protect this endpoint
      handleGetAllBills();
      break;
    default:
      http_response_code(400);
      echo json_encode(['status' => 'error', 'message' => '無效的操作']);
      break;
  }
} catch (Exception $e) {
  error_log("Admin API Error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['status' => 'error', 'message' => '伺服器內部錯誤']);
}
