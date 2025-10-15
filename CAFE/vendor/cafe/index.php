<?php
// Cafe registration APIs: POST, GET, PUT, DELETE (vendor token required)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');

// Always use Asia/Kolkata timezone for all PHP date/time functions
date_default_timezone_set('Asia/Kolkata');
require_once '../../common_cafe/db.php';
// Force MySQL session to Asia/Kolkata timezone for all queries
if (isset($conn) && $conn) {
	$conn->query("SET time_zone = '+05:30'");
}
require_once '../../common_cafe/jwt_secret.php';

header('Content-Type: application/json');

// Helper: Get Bearer token from Authorization header
function getBearerToken() {
	$header = null;
	if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
		$header = $_SERVER['HTTP_AUTHORIZATION'];
	} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
		$header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
	}
	if ($header && preg_match('/Bearer\s(.*)/', $header, $matches)) {
		return $matches[1];
	}
	return null;
}

// Helper: Validate vendor token (JWT)
function validateVendorToken($token) {
	require_once '../../../vendor/firebase/php-jwt/src/JWT.php';
	require_once '../../../vendor/firebase/php-jwt/src/Key.php';
	$secret = JWT_SECRET; // from jwt_secret.php
	try {
		$decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
		// Vendor token: has 'sub', User token: has 'user_id'
		if (isset($decoded->sub)) {
			return [
				'type' => 'vendor',
				'vendor_id' => $decoded->sub,
				'owner_name' => isset($decoded->name) ? $decoded->name : '',
				'owner_email' => isset($decoded->email) ? $decoded->email : '',
				'owner_mobile' => isset($decoded->mobile) ? $decoded->mobile : ''
			];
		} elseif (isset($decoded->user_id)) {
			return [
				'type' => 'user',
				'user_id' => $decoded->user_id,
				'user_name' => isset($decoded->name) ? $decoded->name : '',
				'user_email' => isset($decoded->email) ? $decoded->email : ''
			];
		}
	} catch (Exception $e) {
		return false;
	}
	return false;
}

$method = $_SERVER['REQUEST_METHOD'];

// Support method override via _method field for PUT/DELETE with form-data
if ($method === 'POST' && isset($_POST['_method'])) {
	$method = strtoupper($_POST['_method']);
}


$token = getBearerToken();
$auth_info = $token ? validateVendorToken($token) : false;
// For vendor
$vendor_id = isset($auth_info['vendor_id']) ? $auth_info['vendor_id'] : null;
$owner_name = isset($auth_info['owner_name']) ? $auth_info['owner_name'] : null;
$owner_email = isset($auth_info['owner_email']) ? $auth_info['owner_email'] : null;
$owner_mobile = isset($auth_info['owner_mobile']) ? $auth_info['owner_mobile'] : null;
// For user
$user_id = isset($auth_info['user_id']) ? $auth_info['user_id'] : null;

// Use $conn from db.php

switch ($method) {
	case 'POST':
		// Require valid token for POST
		if ($auth_info === false || $auth_info === null || !isset($auth_info['type']) || $auth_info['type'] !== 'vendor') {
			http_response_code(401);
			echo json_encode(['error' => 'Unauthorized: Invalid or missing token']);
			exit;
		}
		// Register new cafe with file uploads
		$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
		$isJson = strpos($contentType, 'application/json') !== false;
		$isMultipart = strpos($contentType, 'multipart/form-data') !== false;

		// If files are present but not multipart/form-data, return error
		if ((isset($_FILES['thumbnailImage']) || isset($_FILES['cafeImages'])) && !$isMultipart) {
			http_response_code(400);
			echo json_encode(['error' => 'File uploads require multipart/form-data. Please send images using form-data, not JSON.']);
			exit;
		}

		if ($isJson) {
			$data = json_decode(file_get_contents('php://input'), true);
		} else {
			$data = $_POST;
		}
		$name = $data['name'] ?? '';
		$address = $data['address'] ?? '';
		$phone = $data['phone'] ?? '';
		$cafe_email = $data['cafe_email'] ?? '';
		$missingFields = [];
		if (!$name) $missingFields[] = 'name';
		if (!$address) $missingFields[] = 'address';
		if (!$phone) $missingFields[] = 'phone';
		if (!$cafe_email) $missingFields[] = 'cafe_email';
		if (!empty($missingFields)) {
			http_response_code(400);
			echo json_encode(['error' => 'Missing required fields', 'fields' => $missingFields]);
			exit;
		}

		// Handle uploads only if multipart/form-data
		$thumbnailUrl = '';
		$cafeImagesUrls = [];
		if ($isMultipart) {
			$uploadDir = __DIR__ . "/uploads/vendor_{$vendor_id}/";
			if (!is_dir($uploadDir)) {
				mkdir($uploadDir, 0777, true);
			}
			$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/uploads/vendor_{$vendor_id}/";

			// Handle thumbnailImage
			if (!isset($_FILES['thumbnailImage']) || $_FILES['thumbnailImage']['error'] !== UPLOAD_ERR_OK) {
				http_response_code(400);
				echo json_encode(['error' => 'thumbnailImage is required and must be uploaded as a file using form-data.']);
				exit;
			}
			$ext = pathinfo($_FILES['thumbnailImage']['name'], PATHINFO_EXTENSION);
			$thumbName = 'thumb_' . time() . '.' . $ext;
			$thumbPath = $uploadDir . $thumbName;
			if (move_uploaded_file($_FILES['thumbnailImage']['tmp_name'], $thumbPath)) {
				$thumbnailUrl = $baseUrl . $thumbName;
			}

			// Handle cafeImages[] robustly
			if (isset($_FILES['cafeImages'])) {
				$cafeImages = $_FILES['cafeImages'];
				if (is_array($cafeImages['tmp_name'])) {
					foreach ($cafeImages['tmp_name'] as $idx => $tmpName) {
						if ($cafeImages['error'][$idx] === UPLOAD_ERR_OK) {
							$ext = pathinfo($cafeImages['name'][$idx], PATHINFO_EXTENSION);
							$imgName = 'cafe_' . time() . "_{$idx}." . $ext;
							$imgPath = $uploadDir . $imgName;
							if (move_uploaded_file($tmpName, $imgPath)) {
								$cafeImagesUrls[] = $baseUrl . $imgName;
							}
						}
					}
				} elseif (is_uploaded_file($cafeImages['tmp_name'])) {
					// Single file upload (not array)
					if ($cafeImages['error'] === UPLOAD_ERR_OK) {
						$ext = pathinfo($cafeImages['name'], PATHINFO_EXTENSION);
						$imgName = 'cafe_' . time() . ".0." . $ext;
						$imgPath = $uploadDir . $imgName;
						if (move_uploaded_file($cafeImages['tmp_name'], $imgPath)) {
							$cafeImagesUrls[] = $baseUrl . $imgName;
						}
					}
				}
			}
		}
		$cafeImagesJson = json_encode($cafeImagesUrls);

		// Insert into DB (add thumbnail_url, cafe_images, created_at, updated_at columns to your table!)
		$now = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
		$stmt = $conn->prepare('INSERT INTO cafes (vendor_id, owner_name, owner_email, owner_mobile, name, address, phone, cafe_email, thumbnail_url, cafe_images, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
		$stmt->bind_param('isssssssssss', $vendor_id, $owner_name, $owner_email, $owner_mobile, $name, $address, $phone, $cafe_email, $thumbnailUrl, $cafeImagesJson, $now, $now);
		if ($stmt->execute()) {
			echo json_encode([
				'success' => true,
				'cafe_id' => $stmt->insert_id,
				'thumbnail_url' => $thumbnailUrl,
				'cafe_images' => $cafeImagesUrls // returns array, not JSON string
			]);
		} else {
			http_response_code(500);
			echo json_encode(['error' => 'Failed to register cafe']);
		}
		break;
	case 'GET':
		// If vendor token, show only vendor's cafes. If user token or no token, show all cafes.
		if ($auth_info !== false && isset($auth_info['type']) && $auth_info['type'] === 'vendor') {
			$stmt = $conn->prepare('SELECT * FROM cafes WHERE vendor_id = ?');
			$stmt->bind_param('i', $vendor_id);
			$stmt->execute();
			$result = $stmt->get_result();
			$cafes = $result->fetch_all(MYSQLI_ASSOC);
		} else {
			// No token or user token: show all cafes
			$result = $conn->query('SELECT * FROM cafes');
			$cafes = $result->fetch_all(MYSQLI_ASSOC);
		}
		// Overwrite created_at and updated_at with local time (Asia/Kolkata)
		foreach ($cafes as &$cafe) {
			foreach (['created_at', 'updated_at'] as $timeField) {
				if (isset($cafe[$timeField]) && $cafe[$timeField]) {
					$dt = new DateTime($cafe[$timeField]);
					$dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
					$cafe[$timeField] = $dt->format('Y-m-d H:i:s');
				}
			}
			// Decode cafe_images JSON string to array for clean response
			if (isset($cafe['cafe_images']) && is_string($cafe['cafe_images'])) {
				$decoded = json_decode($cafe['cafe_images'], true);
				if (is_array($decoded)) {
					$cafe['cafe_images'] = $decoded;
				}
			}
		}
		echo json_encode(['cafes' => $cafes]);
		break;
	case 'PUT':
		// Require valid token for PUT
		if ($auth_info === false || $auth_info === null || !isset($auth_info['type']) || $auth_info['type'] !== 'vendor') {
			http_response_code(401);
			echo json_encode(['error' => 'Unauthorized: Invalid or missing token']);
			exit;
		}
		// Update cafe details - works exactly like POST
		$cafe_id = isset($_POST['cafe_id']) ? trim($_POST['cafe_id']) : '';
		$name = isset($_POST['name']) ? trim($_POST['name']) : '';
		$address = isset($_POST['address']) ? trim($_POST['address']) : '';
		$phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
		$cafe_email = isset($_POST['cafe_email']) ? trim($_POST['cafe_email']) : '';

		$missingFields = [];
		if ($cafe_id === '' || !is_numeric($cafe_id)) $missingFields[] = 'cafe_id';
		if ($name === '') $missingFields[] = 'name';
		if ($address === '') $missingFields[] = 'address';
		if ($phone === '') $missingFields[] = 'phone';
		if ($cafe_email === '') $missingFields[] = 'cafe_email';
		
		if (!empty($missingFields)) {
			http_response_code(400);
			echo json_encode(['error' => 'Missing required fields', 'fields' => $missingFields]);
			exit;
		}

		// Get current images from DB
		$stmt = $conn->prepare('SELECT thumbnail_url, cafe_images FROM cafes WHERE id = ? AND vendor_id = ?');
		$stmt->bind_param('ii', $cafe_id, $vendor_id);
		$stmt->execute();
		$result = $stmt->get_result();
		$current = $result->fetch_assoc();
		
		if (!$current) {
			http_response_code(404);
			echo json_encode(['error' => 'Cafe not found or you do not have permission to update it']);
			exit;
		}
		
		$thumbnailUrl = $current['thumbnail_url'] ?? '';
		$cafeImagesUrls = isset($current['cafe_images']) ? json_decode($current['cafe_images'], true) : [];

		// Handle new uploads
		$uploadDir = __DIR__ . "/uploads/vendor_{$vendor_id}/";
		if (!is_dir($uploadDir)) {
			mkdir($uploadDir, 0777, true);
		}
		$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/uploads/vendor_{$vendor_id}/";

		// If new thumbnailImage uploaded, replace
		if (isset($_FILES['thumbnailImage']) && $_FILES['thumbnailImage']['error'] === UPLOAD_ERR_OK) {
			$ext = pathinfo($_FILES['thumbnailImage']['name'], PATHINFO_EXTENSION);
			$thumbName = 'thumb_' . time() . '.' . $ext;
			$thumbPath = $uploadDir . $thumbName;
			if (move_uploaded_file($_FILES['thumbnailImage']['tmp_name'], $thumbPath)) {
				$thumbnailUrl = $baseUrl . $thumbName;
			}
		}

		// If new cafeImages uploaded, replace all
		if (isset($_FILES['cafeImages'])) {
			$newCafeImagesUrls = [];
			$cafeImages = $_FILES['cafeImages'];
			if (is_array($cafeImages['tmp_name'])) {
				foreach ($cafeImages['tmp_name'] as $idx => $tmpName) {
					if ($cafeImages['error'][$idx] === UPLOAD_ERR_OK) {
						$ext = pathinfo($cafeImages['name'][$idx], PATHINFO_EXTENSION);
						$imgName = 'cafe_' . time() . "_{$idx}." . $ext;
						$imgPath = $uploadDir . $imgName;
						if (move_uploaded_file($tmpName, $imgPath)) {
							$newCafeImagesUrls[] = $baseUrl . $imgName;
						}
					}
				}
			} elseif (is_uploaded_file($cafeImages['tmp_name'])) {
				if ($cafeImages['error'] === UPLOAD_ERR_OK) {
					$ext = pathinfo($cafeImages['name'], PATHINFO_EXTENSION);
					$imgName = 'cafe_' . time() . ".0." . $ext;
					$imgPath = $uploadDir . $imgName;
					if (move_uploaded_file($cafeImages['tmp_name'], $imgPath)) {
						$newCafeImagesUrls[] = $baseUrl . $imgName;
					}
				}
			}
			if (!empty($newCafeImagesUrls)) {
				$cafeImagesUrls = $newCafeImagesUrls;
			}
		}

		// Update DB with new details and set updated_at to local time
		$cafeImagesJson = json_encode($cafeImagesUrls);
		$now = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
		$stmt = $conn->prepare('UPDATE cafes SET name = ?, address = ?, phone = ?, cafe_email = ?, thumbnail_url = ?, cafe_images = ?, updated_at = ? WHERE id = ? AND vendor_id = ?');
		$stmt->bind_param('sssssssii', $name, $address, $phone, $cafe_email, $thumbnailUrl, $cafeImagesJson, $now, $cafe_id, $vendor_id);
		if ($stmt->execute()) {
			echo json_encode([
				'success' => true,
				'thumbnail_url' => $thumbnailUrl,
				'cafe_images' => $cafeImagesUrls,
				'cafe_email' => $cafe_email
			]);
		} else {
			http_response_code(500);
			echo json_encode(['error' => 'Failed to update cafe']);
		}
		break;
	case 'DELETE':
		// Require valid token for DELETE
		if ($auth_info === false || $auth_info === null || !isset($auth_info['type']) || $auth_info['type'] !== 'vendor') {
			http_response_code(401);
			echo json_encode(['error' => 'Unauthorized: Invalid or missing token']);
			exit;
		}
		// Delete cafe - support both JSON and form-data
		if (isset($_POST['cafe_id'])) {
			// From form-data (when using _method)
			$cafe_id = $_POST['cafe_id'];
		} else {
			// From JSON body
			$data = json_decode(file_get_contents('php://input'), true);
			$cafe_id = $data['cafe_id'] ?? 0;
		}
		
		if (!$cafe_id || !is_numeric($cafe_id)) {
			http_response_code(400);
			echo json_encode(['error' => 'Missing or invalid cafe_id']);
			exit;
		}
		$stmt = $conn->prepare('DELETE FROM cafes WHERE id = ? AND vendor_id = ?');
		$stmt->bind_param('ii', $cafe_id, $vendor_id);
		if ($stmt->execute()) {
			echo json_encode(['success' => true]);
		} else {
			http_response_code(500);
			echo json_encode(['error' => 'Failed to delete cafe']);
		}
		break;
	default:
		http_response_code(405);
		echo json_encode(['error' => 'Method not allowed']);
}