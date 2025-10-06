<?php
/**
 * System Logger Class
 * Handles all system activity logging for audit trails
 */

class SystemLogger {
    private $conn;
    private $user_id;
    private $username;

    public function __construct($conn, $user_id = null, $username = null) {
        $this->conn = $conn;
        $this->user_id = $user_id;
        $this->username = $username ?: 'system';
    }

    /**
     * Log user activity
     */
    public function logActivity($activity_type, $description, $table_affected = null, $record_id = null, $old_values = null, $new_values = null) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO tbl_logs (
                    user_id, username, activity_type, activity_description,
                    table_affected, record_id, old_values, new_values,
                    ip_address, user_agent
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $ip_address = $this->getClientIP();
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $params = [
                $this->user_id,
                $this->username,
                $activity_type,
                $description,
                $table_affected,
                $record_id,
                $old_values ? json_encode($old_values) : null,
                $new_values ? json_encode($new_values) : null,
                $ip_address,
                $user_agent
            ];

            $stmt->execute($params);

            return true;

        } catch (Exception $e) {
            error_log("Logging error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log user login
     */
    public function logLogin($user_id, $username) {
        return $this->logActivity(
            'user_login',
            "User {$username} logged into the system",
            'users',
            $user_id
        );
    }

    /**
     * Log user logout
     */
    public function logLogout($user_id, $username) {
        return $this->logActivity(
            'user_logout',
            "User {$username} logged out of the system",
            'users',
            $user_id
        );
    }

    /**
     * Log client creation
     */
    public function logClientCreated($client_id, $client_data) {
        return $this->logActivity(
            'client_created',
            "New client created: {$client_data['Firstname']} {$client_data['Surname']}",
            'tbl_clients',
            $client_id,
            null,
            $client_data
        );
    }

    /**
     * Log client update
     */
    public function logClientUpdated($client_id, $old_data, $new_data) {
        return $this->logActivity(
            'client_updated',
            "Client record updated: {$new_data['Firstname']} {$new_data['Surname']}",
            'tbl_clients',
            $client_id,
            $old_data,
            $new_data
        );
    }

    /**
     * Log client deletion
     */
    public function logClientDeleted($client_id, $client_name) {
        return $this->logActivity(
            'client_deleted',
            "Client deleted: {$client_name}",
            'tbl_clients',
            $client_id
        );
    }

    /**
     * Log business creation
     */
    public function logBusinessCreated($business_id, $business_data) {
        return $this->logActivity(
            'business_created',
            "New business created: {$business_data['Business_Name']}",
            'tbl_client_business',
            $business_id,
            null,
            $business_data
        );
    }

    /**
     * Log business update
     */
    public function logBusinessUpdated($business_id, $old_data, $new_data) {
        return $this->logActivity(
            'business_updated',
            "Business updated: {$new_data['Business_Name']}",
            'tbl_client_business',
            $business_id,
            $old_data,
            $new_data
        );
    }

    /**
     * Log business deletion
     */
    public function logBusinessDeleted($business_id, $business_name) {
        return $this->logActivity(
            'business_deleted',
            "Business deleted: {$business_name}",
            'tbl_client_business',
            $business_id
        );
    }

    /**
     * Log animal creation
     */
    public function logAnimalCreated($animal_id, $animal_data) {
        return $this->logActivity(
            'animal_created',
            "New animal type created: {$animal_data['Animal']}",
            'tbl_animals',
            $animal_id,
            null,
            $animal_data
        );
    }

    /**
     * Log animal update
     */
    public function logAnimalUpdated($animal_id, $old_data, $new_data) {
        return $this->logActivity(
            'animal_updated',
            "Animal type updated: {$new_data['Animal']}",
            'tbl_animals',
            $animal_id,
            $old_data,
            $new_data
        );
    }

    /**
     * Log animal deletion
     */
    public function logAnimalDeleted($animal_id, $animal_name) {
        return $this->logActivity(
            'animal_deleted',
            "Animal type deleted: {$animal_name}",
            'tbl_animals',
            $animal_id
        );
    }

    /**
     * Log slaughter operation creation
     */
    public function logSlaughterCreated($slaughter_id, $slaughter_data) {
        return $this->logActivity(
            'slaughter_created',
            "New slaughter operation recorded for client: {$slaughter_data['client_name']}",
            'tbl_slaughter',
            $slaughter_id,
            null,
            $slaughter_data
        );
    }

    /**
     * Log slaughter operation update
     */
    public function logSlaughterUpdated($slaughter_id, $old_data, $new_data) {
        return $this->logActivity(
            'slaughter_updated',
            "Slaughter operation updated for client: {$new_data['client_name']}",
            'tbl_slaughter',
            $slaughter_id,
            $old_data,
            $new_data
        );
    }

    /**
     * Log slaughter operation deletion
     */
    public function logSlaughterDeleted($slaughter_id, $client_name) {
        return $this->logActivity(
            'slaughter_deleted',
            "Slaughter operation deleted for client: {$client_name}",
            'tbl_slaughter',
            $slaughter_id
        );
    }

    /**
     * Log fee entry creation
     */
    public function logFeeEntryCreated($fee_id, $fee_data) {
        return $this->logActivity(
            'fee_entry_created',
            "Fee entry recorded: ₱{$fee_data['total_amount']} for {$fee_data['client_name']}",
            'tbl_fees',
            $fee_id,
            null,
            $fee_data
        );
    }

    /**
     * Log fee entry update
     */
    public function logFeeEntryUpdated($fee_id, $old_data, $new_data) {
        return $this->logActivity(
            'fee_entry_updated',
            "Fee entry updated: ₱{$new_data['total_amount']} for {$new_data['client_name']}",
            'tbl_fees',
            $fee_id,
            $old_data,
            $new_data
        );
    }

    /**
     * Log system configuration changes
     */
    public function logSystemConfig($config_type, $description, $old_value = null, $new_value = null) {
        return $this->logActivity(
            'system_config',
            $description,
            'system_config',
            $config_type,
            $old_value,
            $new_value
        );
    }

    /**
     * Log report generation
     */
    public function logReportGenerated($report_type, $parameters = null) {
        return $this->logActivity(
            'report_generated',
            "Report generated: {$report_type}",
            'reports',
            null,
            $parameters ? json_encode($parameters) : null,
            ['report_type' => $report_type, 'generated_by' => $this->username]
        );
    }

    /**
     * Get client IP address
     */
    private function getClientIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        }
    }

    /**
     * Get recent logs
     */
    public function getRecentLogs($limit = 50, $activity_type = null) {
        try {
            $sql = "SELECT * FROM tbl_logs WHERE 1=1";
            $params = [];

            if ($activity_type) {
                $sql .= " AND activity_type = ?";
                $params[] = $activity_type;
            }

            $sql .= " ORDER BY created_at DESC LIMIT " . intval($limit);

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);

            $logs = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $row['old_values'] = $row['old_values'] ? json_decode($row['old_values'], true) : null;
                $row['new_values'] = $row['new_values'] ? json_decode($row['new_values'], true) : null;
                $logs[] = $row;
            }

            return $logs;

        } catch (Exception $e) {
            error_log("Error getting logs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get logs by user
     */
    public function getLogsByUser($user_id, $limit = 100) {
        try {
            $stmt = $this->conn->prepare("
                SELECT * FROM tbl_logs
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$user_id, $limit]);

            $logs = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $row['old_values'] = $row['old_values'] ? json_decode($row['old_values'], true) : null;
                $row['new_values'] = $row['new_values'] ? json_decode($row['new_values'], true) : null;
                $logs[] = $row;
            }

            return $logs;

        } catch (Exception $e) {
            error_log("Error getting user logs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get logs by date range
     */
    public function getLogsByDateRange($start_date, $end_date, $activity_type = null) {
        try {
            $sql = "SELECT * FROM tbl_logs WHERE created_at BETWEEN ? AND ?";
            $params = [$start_date, $end_date];

            if ($activity_type) {
                $sql .= " AND activity_type = ?";
                $params[] = $activity_type;
            }

            $sql .= " ORDER BY created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);

            $logs = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $row['old_values'] = $row['old_values'] ? json_decode($row['old_values'], true) : null;
                $row['new_values'] = $row['new_values'] ? json_decode($row['new_values'], true) : null;
                $logs[] = $row;
            }

            return $logs;

        } catch (Exception $e) {
            error_log("Error getting logs by date range: " . $e->getMessage());
            return [];
        }
    }
}
?>