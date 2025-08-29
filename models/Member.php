<?php

class Member {
    //新增用戶
    public static function registerMember($db,$groupId, $userId) {
        // 先檢查成員是否已存在
        if (self::isMemberRegistered($db, $groupId, $userId)) {
            return false;
        }

        $sql = "INSERT INTO group_members (group_id, user_id) VALUES (?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ss", $groupId, $userId);
        
        $success = $stmt->execute();
        $stmt->close();
        
        return $success;
    }
    //判斷用戶是否已點擊過加入按鈕
    private static function isMemberRegistered($db,$groupId, $userId) {
        $sql = "SELECT id FROM group_members WHERE group_id = ? AND user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ss", $groupId, $userId);
        $stmt->execute();
        $stmt->store_result();
        
        $isRegistered = $stmt->num_rows > 0;
        $stmt->close();

        return $isRegistered;
    }
    //獲取群組成員
    public static function getGroupMembers($db,$groupId){
        $sql = "SELECT user_id FROM group_members WHERE group_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $groupId);
        $stmt->execute();

        $result = $stmt->get_result();
        $members = [];
        while ($row = $result->fetch_assoc()) {
            $members[] = $row['user_id'];
        }
        
        $stmt->close();
        return $members;
    }
}