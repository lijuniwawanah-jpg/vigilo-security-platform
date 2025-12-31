<?php
require_once "config/db.php";

echo "<h2>Database Tables in vigilo_db</h2>";
echo "<style>table {border-collapse: collapse;} td, th {border: 1px solid #ccc; padding: 8px;}</style>";

// Get all tables
$result = $conn->query("SHOW TABLES");
$tables = [];
while ($row = $result->fetch_array()) {
    $tables[] = $row[0];
}

foreach ($tables as $table) {
    echo "<h3>Table: $table</h3>";
    
    // Get table structure
    $result2 = $conn->query("DESCRIBE $table");
    echo "<table>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    
    while ($row = $result2->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['Field']}</td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Key']}</td>";
        echo "<td>{$row['Default']}</td>";
        echo "<td>{$row['Extra']}</td>";
        echo "</tr>";
    }
    echo "</table><hr>";
}

$conn->close();
?>