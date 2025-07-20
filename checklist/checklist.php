<?php
// Backend API for the PPC Checklist (v3 with Name of PPC)

header('Content-Type: application/json');

define('DB_PATH', __DIR__ . '/checklist.db');

// --- DB Connection Function (no changes) ---
function get_db() {
    if (!file_exists(DB_PATH)) {
        http_response_code(500);
        echo json_encode(['error' => 'Database file not found.']);
        exit;
    }
    try {
        $db = new SQLite3(DB_PATH);
        $db->enableExceptions(true);
        return $db;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$db = get_db();
$action = $_GET['action'] ?? null;

// --- Handle Publishing to JSON ---
if ($method === 'GET' && $action === 'publish') {
    try {
        // UPDATED: Added 'name_of_ppc' to the query
        $stmt = $db->prepare("SELECT id, name_of_ppc, pincode, post_office, district, collected FROM ppc_checklist ORDER BY pincode ASC");
        $result = $stmt->execute();
        
        $items = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['collected'] = (int)$row['collected'] === 1;
            $items[] = $row;
        }
        
        file_put_contents('checklist-data.json', json_encode($items, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'message' => 'checklist-data.json has been generated.']);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to generate JSON file: ' . $e->getMessage()]);
    }

// --- Handle Fetching Data for Local View ---
} elseif ($method === 'GET') {
    try {
        // UPDATED: Added 'name_of_ppc' to the query
        $stmt = $db->prepare("SELECT id, name_of_ppc, pincode, post_office, district, collected FROM ppc_checklist ORDER BY pincode ASC");
        $result = $stmt->execute();
        
        $items = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['collected'] = (int)$row['collected'] === 1;
            $items[] = $row;
        }
        
        echo json_encode($items);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database query failed: ' . $e->getMessage()]);
    }

// --- Handle Updating an Item (no changes) ---
} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['id']) || !isset($data['collected'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input.']);
        exit;
    }

    $id = filter_var($data['id'], FILTER_VALIDATE_INT);
    $collected = $data['collected'] ? 1 : 0;

    try {
        $stmt = $db->prepare("UPDATE ppc_checklist SET collected = :collected WHERE id = :id");
        $stmt->bindValue(':collected', $collected, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();

        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database update failed: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
}