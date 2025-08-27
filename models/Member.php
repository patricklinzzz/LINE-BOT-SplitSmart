<?php
require_once __DIR__ . '/../models/Bill.php';
require_once __DIR__ . '/../models/Participant.php';
require_once __DIR__ . '/../models/DbConnection.php';

class Member {
    public static function registerMember($db,$groupId, $userId) {
        if (self::isMemberRegistered($db,$groupId, $userId)) {
            return false;
        }

        $sql = "INSERT INTO group_members (group_id, user_id) VALUES (?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ss", $groupId, $userId);

        if ($stmt->execute()) {
            $stmt->close();
            return true;
        } else {
            $stmt->close();
            return false;
        }
    }

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
}