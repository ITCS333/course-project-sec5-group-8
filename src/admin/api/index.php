<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../common/db.php';

$db = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];

$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true) ?? [];

$id = $_GET['id'] ?? null;
$action = $_GET['action'] ?? null;

function getUsers($db) {
    $query = "SELECT id, name, email, is_admin, created_at FROM users";
    $params = [];

    if (!empty($_GET['search'])) {
        $query .= " WHERE name LIKE :search OR email LIKE :search";
        $params[':search'] = '%' . $_GET['search'] . '%';
    }

    $allowedSort = ['name', 'email', 'is_admin'];
    $sort = in_array($_GET['sort'] ?? '', $allowedSort) ? $_GET['sort'] : 'name';
    $order = strtolower($_GET['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

    $query .= " ORDER BY $sort $order";

    $stmt = $db->prepare($query);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    sendResponse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getUserById($db, $id) {
    if (!$id || !is_numeric($id)) {
        sendResponse('Invalid user id', 400);
    }

    $stmt = $db->prepare("SELECT id, name, email, is_admin, created_at FROM users WHERE id = :id");
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendResponse('User not found', 404);
    }

    sendResponse($user);
}

function createUser($db, $data) {
    $name = sanitizeInput($data['name'] ?? '');
    $email = sanitizeInput($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $isAdmin = isset($data['is_admin']) && (int)$data['is_admin'] === 1 ? 1 : 0;

    if ($name === '' || $email === '' || $password === '') {
        sendResponse('Name, email, and password are required', 400);
    }

    if (!validateEmail($email)) {
        sendResponse('Invalid email format', 400);
    }

    if (strlen($password) < 8) {
        sendResponse('Password must be at least 8 characters', 400);
    }

    $check = $db->prepare("SELECT id FROM users WHERE email = :email");
    $check->bindValue(':email', $email);
    $check->execute();

    if ($check->fetch()) {
        sendResponse('Email already exists', 409);
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare(
        "INSERT INTO users (name, email, password, is_admin)
         VALUES (:name, :email, :password, :is_admin)"
    );

    $stmt->bindValue(':name', $name);
    $stmt->bindValue(':email', $email);
    $stmt->bindValue(':password', $hashedPassword);
    $stmt->bindValue(':is_admin', $isAdmin, PDO::PARAM_INT);

    if ($stmt->execute()) {
        sendResponse(['id' => $db->lastInsertId()], 201);
    }

    sendResponse('Failed to create user', 500);
}

function updateUser($db, $data) {
    if (empty($data['id'])) {
        sendResponse('User id is required', 400);
    }

    $id = (int)$data['id'];

    $check = $db->prepare("SELECT id FROM users WHERE id = :id");
    $check->bindValue(':id', $id, PDO::PARAM_INT);
    $check->execute();

    if (!$check->fetch()) {
        sendResponse('User not found', 404);
    }

    $fields = [];
    $params = [':id' => $id];

    if (isset($data['name'])) {
        $fields[] = "name = :name";
        $params[':name'] = sanitizeInput($data['name']);
    }

    if (isset($data['email'])) {
        $email = sanitizeInput($data['email']);

        if (!validateEmail($email)) {
            sendResponse('Invalid email format', 400);
        }

        $emailCheck = $db->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
        $emailCheck->bindValue(':email', $email);
        $emailCheck->bindValue(':id', $id, PDO::PARAM_INT);
        $emailCheck->execute();

        if ($emailCheck->fetch()) {
            sendResponse('Email already exists', 409);
        }

        $fields[] = "email = :email";
        $params[':email'] = $email;
    }

    if (isset($data['is_admin'])) {
        $fields[] = "is_admin = :is_admin";
        $params[':is_admin'] = (int)$data['is_admin'] === 1 ? 1 : 0;
    }

    if (empty($fields)) {
        sendResponse('No fields to update', 400);
    }

    $query = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = :id";
    $stmt = $db->prepare($query);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    if ($stmt->execute()) {
        sendResponse('User updated successfully');
    }

    sendResponse('Failed to update user', 500);
}

function deleteUser($db, $id) {
    if (!$id || !is_numeric($id)) {
        sendResponse('Invalid user id', 400);
    }

    $check = $db->prepare("SELECT id FROM users WHERE id = :id");
    $check->bindValue(':id', $id, PDO::PARAM_INT);
    $check->execute();

    if (!$check->fetch()) {
        sendResponse('User not found', 404);
    }

    $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);

    if ($stmt->execute()) {
        sendResponse('User deleted successfully');
    }

    sendResponse('Failed to delete user', 500);
}

function changePassword($db, $data) {
    if (empty($data['id']) || empty($data['current_password']) || empty($data['new_password'])) {
        sendResponse('Missing required fields', 400);
    }

    if (strlen($data['new_password']) < 8) {
        sendResponse('New password must be at least 8 characters', 400);
    }

    $stmt = $db->prepare("SELECT password FROM users WHERE id = :id");
    $stmt->bindValue(':id', (int)$data['id'], PDO::PARAM_INT);
    $stmt->execute();

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendResponse('User not found', 404);
    }

    if (!password_verify($data['current_password'], $user['password'])) {
        sendResponse('Current password is incorrect', 401);
    }

    $newHash = password_hash($data['new_password'], PASSWORD_DEFAULT);

    $update = $db->prepare("UPDATE users SET password = :password WHERE id = :id");
    $update->bindValue(':password', $newHash);
    $update->bindValue(':id', (int)$data['id'], PDO::PARAM_INT);

    if ($update->execute()) {
        sendResponse('Password changed successfully');
    }

    sendResponse('Failed to change password', 500);
}

try {
    if ($method === 'GET') {
        if ($id) {
            getUserById($db, $id);
        } else {
            getUsers($db);
        }
    } elseif ($method === 'POST') {
        if ($action === 'change_password') {
            changePassword($db, $data);
        } else {
            createUser($db, $data);
        }
    } elseif ($method === 'PUT') {
        updateUser($db, $data);
    } elseif ($method === 'DELETE') {
        deleteUser($db, $id);
    } else {
        sendResponse('Method not allowed', 405);
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    sendResponse('Database error', 500);
} catch (Exception $e) {
    sendResponse($e->getMessage(), 500);
}

function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);

    if ($statusCode < 400) {
        echo json_encode(['success' => true, 'data' => $data]);
    } else {
        echo json_encode(['success' => false, 'message' => $data]);
    }

    exit;
}

function validateEmail($email) {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}
?>