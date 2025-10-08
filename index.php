<?php
// Simple index page to check database connection
include __DIR__ . '/common/db.php';

if (isset($conn) && $conn && $conn->ping()) {
    // Get current database name
    $result = $conn->query("SELECT DATABASE() as dbname");
    $row = $result->fetch_assoc();
    $dbname = $row['dbname'];

    echo "✅ Database connected: " . $dbname;
} else {
    echo "❌ Database connection failed";
}
?>
