<?php
$rootPath = dirname(__DIR__);
require_once $rootPath . '/includes/config.php';
require_once $rootPath . '/includes/auth.php';

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check permissions
requirePermission('bulk_import');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Log the request for debugging
        error_log("Bulk Import POST request received: " . print_r($_POST, true));
        error_log("Session data: " . print_r($_SESSION, true));
        
        // Check if PDO connection exists
        if (!isset($pdo) || !$pdo) {
            throw new Exception('No database connection available');
        }
        
        $pdo->beginTransaction();
        
        if (isset($_POST['import_method'])) {
            $importMethod = $_POST['import_method'];
            $providerId = $_POST['provider_id'];
            $category = $_POST['category'] ?? null;
            $priceAdjustmentType = $_POST['price_adjustment_type'] ?? 'none';
            $priceAdjustmentValue = isset($_POST['price_adjustment_value']) ? floatval($_POST['price_adjustment_value']) : 0;
            
            // Log import parameters
            error_log("Import parameters: Method={$importMethod}, Provider={$providerId}, Category={$category}, AdjustmentType={$priceAdjustmentType}, AdjustmentValue={$priceAdjustmentValue}");
            
            if ($importMethod === 'api') {
                // Get provider API details
                $stmt = $pdo->prepare("SELECT api_url, api_key FROM providers WHERE id = ?");
                $stmt->execute([$providerId]);
                $provider = $stmt->fetch();
                
                if (!$provider) {
                    throw new Exception('Provider not found');
                }
                
                // Build API URL with category filter if specified
                $apiUrl = $provider['api_url'] . '/services';
                if ($category) {
                    $apiUrl .= '?category=' . urlencode($category);
                }
                
                // Fetch services from provider API
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $apiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $provider['api_key'],
                    'Content-Type: application/json'
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode !== 200) {
                    throw new Exception('Failed to fetch services from provider');
                }
                
                $services = json_decode($response, true);
                
                if (!$services) {
                    throw new Exception('Invalid JSON response from provider API');
                }
                
                // Import all services from API
                $importCount = 0;
                $errorCount = 0;
                
                foreach ($services as $service) {
                    try {
                        // Validate required fields
                        if (empty($service['name']) || empty($service['category']) || !isset($service['price'])) {
                            throw new Exception('Missing required fields in API service');
                        }
                        
                        // Extract service data
                        $name = $service['name'];
                        $categoryName = $service['category'];
                        $description = $service['description'] ?? '';
                        $min = intval($service['min_quantity'] ?? 1);
                        $max = intval($service['max_quantity'] ?? 1000);
                        $price = floatval($service['price']);
                        $avg_speed = floatval($service['avg_speed'] ?? 0);
                        
                        // Apply price adjustment
                        $originalPrice = $price;
                        $adjustedPrice = $originalPrice;
                        
                        if ($priceAdjustmentType === 'percentage') {
                            $adjustedPrice = $originalPrice + ($originalPrice * ($priceAdjustmentValue / 100));
                        } elseif ($priceAdjustmentType === 'fixed') {
                            $adjustedPrice = $originalPrice + $priceAdjustmentValue;
                        }
                        
                        // Get or create category
                        $categoryStmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND provider_id = ?");
                        $categoryStmt->execute([$categoryName, $providerId]);
                        $categoryRow = $categoryStmt->fetch();
                        
                        if (!$categoryRow) {
                            // Create new category
                            $categoryStmt = $pdo->prepare("INSERT INTO categories (name, provider_id) VALUES (?, ?)");
                            $categoryStmt->execute([$categoryName, $providerId]);
                            $categoryId = $pdo->lastInsertId();
                        } else {
                            $categoryId = $categoryRow['id'];
                        }
                        
                        // Insert or update service
                        $stmt = $pdo->prepare("
                            INSERT INTO services (
                                provider_id, category_id, name, description, 
                                min_quantity, max_quantity, price, avg_speed, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
                            ON DUPLICATE KEY UPDATE 
                                category_id = VALUES(category_id),
                                description = VALUES(description),
                                min_quantity = VALUES(min_quantity),
                                max_quantity = VALUES(max_quantity),
                                price = VALUES(price),
                                avg_speed = VALUES(avg_speed),
                                updated_at = NOW()
                        ");
                        
                        $stmt->execute([
                            $providerId,
                            $categoryId,
                            $name,
                            $description,
                            $min,
                            $max,
                            $adjustedPrice,
                            $avg_speed
                        ]);
                        
                        $importCount++;
                    } catch (Exception $e) {
                        $errorCount++;
                        error_log("Error importing API service: " . $e->getMessage());
                        error_log("Service Data: " . print_r($service, true));
                    }
                }
                
                $pdo->commit();
                
                $_SESSION['success'] = "Successfully imported {$importCount} services from API.";
                if ($errorCount > 0) {
                    $_SESSION['success'] .= " {$errorCount} errors occurred.";
                }
                header('Location: bulk_import.php');
                exit;
                
            } elseif ($importMethod === 'csv') {
                if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('No CSV file uploaded or upload error occurred');
                }
                
                $csvFile = $_FILES['csv_file']['tmp_name'];
                error_log("Attempting to open CSV file: " . $csvFile);
                
                $handle = fopen($csvFile, 'r');
                
                if (!$handle) {
                    throw new Exception('Failed to open CSV file');
                }
                
                $importCount = 0;
                $errorCount = 0;
                $errors = [];
                
                // Skip header row if exists
                $firstRow = fgetcsv($handle, 0, ',');
                if ($firstRow && strtolower($firstRow[0]) === 'name') {
                    // This is likely a header row, skip it
                    error_log("Skipping header row: " . print_r($firstRow, true));
                } else {
                    // This is data, reset file pointer
                    rewind($handle);
                }
                
                // Process CSV file
                error_log("Starting CSV processing...");
                $rowNumber = 1;
                
                while (($data = fgetcsv($handle, 0, ',')) !== false) {
                    $rowNumber++;
                    try {
                        error_log("Processing CSV row {$rowNumber}: " . print_r($data, true));
                        
                        // Skip empty rows
                        if (count($data) < 7 || empty(trim($data[0]))) {
                            error_log("Skipping empty row {$rowNumber}");
                            continue;
                        }
                        
                        // Extract data from CSV row
                        $name = trim($data[0]);
                        $categoryName = trim($data[1]);
                        $description = trim($data[2]);
                        $min = intval($data[3]);
                        $max = intval($data[4]);
                        $price = floatval($data[5]);
                        $avg_speed = floatval($data[6]);
                        
                        error_log("Extracted data: Name={$name}, Category={$categoryName}, Price={$price}");
                        
                        // Validate required fields
                        if (empty($name) || empty($categoryName) || $price <= 0) {
                            throw new Exception("Missing required fields in CSV row {$rowNumber}");
                        }
                        
                        error_log("Converted values: Min={$min}, Max={$max}, Price={$price}, AvgSpeed={$avg_speed}");
                        
                        // Apply price adjustment
                        $originalPrice = $price;
                        $adjustedPrice = $originalPrice;
                        
                        if ($priceAdjustmentType === 'percentage') {
                            $adjustedPrice = $originalPrice + ($originalPrice * ($priceAdjustmentValue / 100));
                            error_log("Applying percentage adjustment: Original={$originalPrice}, Adjustment={$priceAdjustmentValue}%, NewPrice={$adjustedPrice}");
                        } elseif ($priceAdjustmentType === 'fixed') {
                            $adjustedPrice = $originalPrice + $priceAdjustmentValue;
                            error_log("Applying fixed adjustment: Original={$originalPrice}, Adjustment={$priceAdjustmentValue}, NewPrice={$adjustedPrice}");
                        }
                        
                        // Get or create category
                        error_log("Checking for existing category: Name={$categoryName}, Provider={$providerId}");
                        $categoryStmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND provider_id = ?");
                        $categoryStmt->execute([$categoryName, $providerId]);
                        $categoryRow = $categoryStmt->fetch();
                        
                        if (!$categoryRow) {
                            error_log("Creating new category: Name={$categoryName}, Provider={$providerId}");
                            $categoryStmt = $pdo->prepare("INSERT INTO categories (name, provider_id) VALUES (?, ?)");
                            $categoryStmt->execute([$categoryName, $providerId]);
                            $categoryId = $pdo->lastInsertId();
                            error_log("Created new category with ID: {$categoryId}");
                        } else {
                            $categoryId = $categoryRow['id'];
                            error_log("Found existing category with ID: {$categoryId}");
                        }
                        
                        // Insert or update service
                        error_log("Attempting to insert/update service: Name={$name}, Category={$categoryId}, Price={$adjustedPrice}");
                        $stmt = $pdo->prepare("
                            INSERT INTO services (
                                provider_id, category_id, name, description, 
                                min_quantity, max_quantity, price, avg_speed, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
                            ON DUPLICATE KEY UPDATE 
                                category_id = VALUES(category_id),
                                description = VALUES(description),
                                min_quantity = VALUES(min_quantity),
                                max_quantity = VALUES(max_quantity),
                                price = VALUES(price),
                                avg_speed = VALUES(avg_speed),
                                updated_at = NOW()
                        ");
                        
                        $stmt->execute([
                            $providerId,
                            $categoryId,
                            $name,
                            $description,
                            $min,
                            $max,
                            $adjustedPrice,
                            $avg_speed
                        ]);
                        
                        error_log("Successfully imported service: {$name} (Category: {$categoryName}, Price: {$adjustedPrice})");
                        $importCount++;
                        
                    } catch (Exception $e) {
                        $errorCount++;
                        $errorMsg = "Row {$rowNumber}: " . $e->getMessage();
                        $errors[] = $errorMsg;
                        error_log("Error processing CSV row {$rowNumber}: " . $e->getMessage());
                        error_log("Row Data: " . print_r($data, true));
                    }
                }
                
                fclose($handle);
                $pdo->commit();
                
                // Prepare error report if needed
                if ($errorCount > 0) {
                    $errorReport = "CSV Import Error Report\n";
                    $errorReport .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
                    $errorReport .= "Summary:\n";
                    $errorReport .= "Successfully imported: {$importCount} services\n";
                    $errorReport .= "Errors encountered: {$errorCount}\n\n";
                    $errorReport .= "Error Details:\n";
                    $errorReport .= implode("\n", $errors);
                    
                    // Ensure logs directory exists
                    $logsDir = $rootPath . '/logs';
                    if (!is_dir($logsDir)) {
                        mkdir($logsDir, 0755, true);
                    }
                    
                    // Save error report
                    $errorFileName = 'csv_import_errors_' . date('Y-m-d_H-i-s') . '.txt';
                    file_put_contents($logsDir . '/' . $errorFileName, $errorReport);
                    
                    $_SESSION['error'] = "{$errorCount} errors occurred during import. Error report saved as {$errorFileName}";
                }
                
                $_SESSION['success'] = "Successfully imported {$importCount} services from CSV.";
                if ($errorCount > 0) {
                    $_SESSION['success'] .= " {$errorCount} errors occurred.";
                }
                header('Location: bulk_import.php');
                exit;
            } else {
                throw new Exception('Invalid import method specified');
            }
        } else {
            throw new Exception('No import method specified');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("CSV Import Error: " . $e->getMessage());
        error_log("Stack Trace: " . $e->getTraceAsString());
        $_SESSION['error'] = "Error processing import: " . $e->getMessage();
        header('Location: bulk_import.php');
        exit;
    }
}

// Get all providers
try {
    $stmt = $pdo->query("SELECT id, name FROM providers WHERE status = 'active' ORDER BY name");
    $providers = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching providers: " . $e->getMessage());
    $providers = [];
}

// Get all categories
try {
    $stmt = $pdo->query("SELECT name FROM categories ORDER BY name");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    $categories = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Bulk Import - <?= APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js"></script>
    <style>
        /* Custom responsive styles */
        @media (max-width: 768px) {
            .modal-content {
                width: 90% !important;
                max-width: 90% !important;
            }
            
            .service-row {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .service-row .flex-1 {
                width: 100%;
            }
            
            .service-row .flex.items-center.space-x-4 {
                margin-top: 1rem;
                width: 100%;
            }
            
            .batch-operations-modal {
                width: 90% !important;
                max-width: 90% !important;
            }
            
            .numeric-keypad {
                grid-template-columns: repeat(3, 1fr) !important;
            }
            
            .numeric-key {
                padding: 0.5rem !important;
                font-size: 1.2rem !important;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include $rootPath . '/includes/sidebar.php'; ?>
    <?php include $rootPath . '/includes/topbar.php'; ?>

    <main class="ml-64 pt-16">
        <div class="container mx-auto px-4">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Bulk Import Services</h1>
            </div>

            <!-- Import Method Selection -->
            <div class="bg-white rounded-2xl shadow-lg p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4">Import Method</h2>
                <form method="POST" class="space-y-6" id="importForm">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Import Method</label>
                        <select name="import_method" id="importMethod" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm rounded-md" required>
                            <option value="api">API Import</option>
                            <option value="csv">CSV Import</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Provider</label>
                        <select name="provider_id" id="providerId" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm rounded-md" required>
                            <?php foreach ($providers as $provider): ?>
                                <option value="<?php echo $provider['id']; ?>"><?php echo htmlspecialchars($provider['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="apiImport" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Category (Optional)</label>
                            <select name="category" id="categorySelect" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm rounded-md">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-gray-700">Price Adjustment</label>
                            <div class="flex space-x-4">
                                <div class="flex items-center space-x-2">
                                    <input type="radio" name="price_adjustment_type" value="none" checked class="h-4 w-4 text-primary focus:ring-primary">
                                    <span class="text-sm text-gray-700">No Adjustment</span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <input type="radio" name="price_adjustment_type" value="percentage" class="h-4 w-4 text-primary focus:ring-primary">
                                    <span class="text-sm text-gray-700">Percentage</span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <input type="radio" name="price_adjustment_type" value="amount" class="h-4 w-4 text-primary focus:ring-primary">
                                    <span class="text-sm text-gray-700">Fixed Amount</span>
                                </div>
                            </div>
                            <div>
                                <input type="number" name="price_adjustment_value" id="priceAdjustmentValue" step="0.01" min="0" placeholder="Enter percentage (e.g., 10) or amount (e.g., 5.00)" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm rounded-md" disabled>
                                <div class="text-sm text-gray-500 mt-1">
                                    Enter percentage (e.g., 10 for 10%) or amount (e.g., 5.00) based on selected adjustment type
                                </div>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <button type="button" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary" onclick="fetchServices()">
                                Fetch Services
                            </button>
                        </div>
                    </div>

                    <div id="csvImport" class="space-y-4 hidden">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">CSV File</label>
                            <input type="file" name="csv_file" accept=".csv" class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-white hover:file:bg-primary-dark" required>
                            <div class="text-sm text-gray-500 mt-1">
                                Download CSV template: <a href="csv_template.csv" class="text-primary hover:text-primary-dark">Download Template</a>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <label class="block text-sm font-medium text-gray-700">Price Adjustment</label>
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <div class="flex space-x-4">
                                    <div class="flex items-center space-x-2">
                                        <input type="radio" name="price_adjustment_type" value="none" checked class="h-4 w-4 text-gray-400 focus:ring-primary">
                                        <span class="text-sm text-gray-500">No Adjustment</span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <input type="radio" name="price_adjustment_type" value="percentage" class="h-4 w-4 text-gray-400 focus:ring-primary">
                                        <span class="text-sm text-gray-500">Percentage</span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <input type="radio" name="price_adjustment_type" value="amount" class="h-4 w-4 text-gray-400 focus:ring-primary">
                                        <span class="text-sm text-gray-500">Fixed Amount</span>
                                    </div>
                                </div>
                                <div id="adjustmentInput" class="mt-4 hidden">
                                    <div class="flex items-center space-x-3">
                                        <div class="relative">
                                        <input type="text" name="price_adjustment_value" id="csvPriceAdjustmentValue" step="0.01" min="0" class="flex-1 mt-1 block pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm rounded-md" readonly>
                                        <div id="numericKeypad" class="absolute bottom-full left-0 mb-2 bg-white border border-gray-300 rounded-lg shadow-lg p-2 hidden">
                                            <div class="grid grid-cols-3 gap-2">
                                                <button class="numeric-key w-full py-2 text-center text-gray-700 hover:bg-gray-100 rounded-md">1</button>
                                                <button class="numeric-key w-full py-2 text-center text-gray-700 hover:bg-gray-100 rounded-md">2</button>
                                                <button class="numeric-key w-full py-2 text-center text-gray-700 hover:bg-gray-100 rounded-md">3</button>
                                                <button class="numeric-key w-full py-2 text-center text-gray-700 hover:bg-gray-100 rounded-md">4</button>
                                                <button class="numeric-key w-full py-2 text-center text-gray-700 hover:bg-gray-100 rounded-md">5</button>
                                                <button class="numeric-key w-full py-2 text-center text-gray-700 hover:bg-gray-100 rounded-md">6</button>
                                                <button class="numeric-key w-full py-2 text-center text-gray-700 hover:bg-gray-100 rounded-md">7</button>
                                                <button class="numeric-key w-full py-2 text-center text-gray-700 hover:bg-gray-100 rounded-md">8</button>
                                                <button class="numeric-key w-full py-2 text-center text-gray-700 hover:bg-gray-100 rounded-md">9</button>
                                                <button class="numeric-key w-full py-2 text-center text-gray-700 hover:bg-gray-100 rounded-md">.</button>
                                                <button class="numeric-key w-full py-2 text-center text-gray-700 hover:bg-gray-100 rounded-md">0</button>
                                                <button class="numeric-key w-full py-2 text-center text-gray-700 hover:bg-gray-100 rounded-md">×</button>
                                            </div>
                                            <div class="mt-2">
                                                <button id="clearButton" class="w-full py-2 text-center text-red-600 hover:bg-red-100 rounded-md">Clear</button>
                                            </div>
                                        </div>
                                    </div>
                                        <span id="adjustmentUnit" class="text-sm text-gray-500">%</span>
                                    </div>
                                    <div class="text-sm text-gray-400 mt-1">
                                        Enter percentage (e.g., 10) or amount (e.g., 5.00)
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pt-4">
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                            Import Services
                        </button>
                    </div>
                </form>
            </div>

            <!-- Step 1: Select Provider -->
            <div id="providerStep" class="bg-white rounded-2xl shadow-lg p-4 mb-4 sm:p-6 sm:mb-6">
                <h2 class="text-xl font-semibold mb-4">Step 1: Select Provider</h2>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Provider</label>
                    <select id="providerSelect" class="mt-1 block w-full sm:w-48 pl-3 pr-10 py-2 text-sm border-gray-300 focus:outline-none focus:ring-primary focus:border-primary rounded-md">
                        <option value="">Select Provider</option>
                        <?php foreach ($providers as $provider): ?>
                            <option value="<?= $provider['id']; ?>"><?= $provider['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex justify-end space-x-2">
                    <button type="button" id="providerInfoBtn" class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-primary hover:text-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                        <i class="fas fa-info-circle mr-1"></i> Info
                    </button>
                    <button type="button" id="providerStatsBtn" class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-primary hover:text-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                        <i class="fas fa-chart-line mr-1"></i> Stats
                    </button>
                    <button type="button" id="nextToCategories" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                        Next
                    </button>
                </div>
            </div>

            <!-- Step 2: Select Categories -->
            <div id="categoryStep" class="bg-white rounded-2xl shadow-lg p-4 mb-4 sm:p-6 sm:mb-6 hidden">
                <h2 class="text-xl font-semibold mb-4">Step 2: Select Categories</h2>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Categories</label>
                    <div id="categoryList" class="space-y-2 overflow-y-auto max-h-[300px]">
                        <!-- Categories will be populated via AJAX -->
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row justify-between items-center mb-4">
                    <span id="categoriesCount">Total Categories: 0</span>
                    <div class="flex items-center space-x-2">
                        <button id="selectAllCategories" class="text-sm text-primary hover:text-primary-dark">
                            Select All
                        </button>
                        <button id="deselectAllCategories" class="text-sm text-gray-500 hover:text-gray-700">
                            Deselect All
                        </button>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row justify-between items-center mb-4 space-y-2 sm:space-y-0">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Price Adjustment</label>
                        <select id="priceAdjustmentType" class="mt-1 block w-full sm:w-48 pl-3 pr-10 py-2 text-sm border-gray-300 focus:outline-none focus:ring-primary focus:border-primary rounded-md">
                            <option value="none">None</option>
                            <option value="percentage">Percentage</option>
                            <option value="fixed">Fixed Amount</option>
                        </select>
                    </div>
                    <div class="flex items-center space-x-2">
                        <input type="text" id="priceAdjustmentValue" class="mt-1 block w-24 sm:w-32 pl-3 pr-10 py-2 text-sm border-gray-300 focus:outline-none focus:ring-primary focus:border-primary rounded-md" readonly>
                        <span id="priceAdjustmentUnit" class="text-sm text-gray-500">%</span>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row justify-between items-center space-y-2 sm:space-y-0">
                    <button type="button" id="backToProvider" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                        Back
                    </button>
                    <button type="button" id="nextToServices" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                        Next
                    </button>
                </div>
            </div>

            <!-- Step 3: Select Services -->
            <div id="serviceStep" class="bg-white rounded-2xl shadow-lg p-6 mb-6 hidden">
                <h2 class="text-xl font-semibold mb-4">Step 3: Select Services</h2>
                <div class="flex justify-between items-center mb-4">
                    <span id="serviceCount">Total Services Found: 0</span>
                    <span class="ml-4">Selected: <span id="selectedCount">0</span></span>
                </div>
                <div class="flex items-center space-x-4">
                    <button id="selectAllBtn" class="text-sm text-primary hover:text-primary-dark">
                        Select All
                    </button>
                    <button id="deselectAllBtn" class="text-sm text-gray-500 hover:text-gray-700">
                        Deselect All
                    </button>
                    <button id="batchOperationsBtn" class="text-sm text-primary hover:text-primary-dark">
                        Batch Operations
                    </button>
                </div>
                <div id="serviceList" class="overflow-x-auto max-h-[600px] overflow-y-auto">
                    <!-- Services will be populated via AJAX -->
                </div>
                <div id="batchOperationsModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
                    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                        <div class="flex justify-between items-center pb-3">
                            <h3 class="text-xl font-bold">Batch Operations</h3>
                            <button class="modal-close cursor-pointer z-50">
                                <svg class="fill-current text-black" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18">
                                    <path d="M14.53 4.53l-1.06-1.06L9 7.94 4.53 3.47 3.47 4.53 7.94 9l-4.47 4.47 1.06 1.06L9 10.06l4.47 4.47 1.06-1.06L10.06 9z"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="p-4 space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Action</label>
                                <select id="batchAction" class="mt-1 block w-full pl-3 pr-10 py-2 text-sm border-gray-300 focus:outline-none focus:ring-primary focus:border-primary rounded-md">
                                    <option value="enable">Enable Services</option>
                                    <option value="disable">Disable Services</option>
                                    <option value="make_secret">Make Secret</option>
                                    <option value="remove_secret">Remove from Secret</option>
                                    <option value="delete">Delete Services</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Confirmation</label>
                                <div class="mt-2">
                                    <label class="flex items-center space-x-2">
                                        <input type="checkbox" id="confirmAction" class="h-4 w-4 text-primary focus:ring-primary">
                                        <span>I confirm this action</span>
                                    </label>
                                </div>
                            </div>
                            <button id="executeBatch" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                                Execute
                            </button>
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="bg-white rounded-lg shadow p-4">
                        <h3 class="text-lg font-medium mb-4">Import Options</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Import Mode</label>
                                <select id="importMode" class="mt-1 block w-full pl-3 pr-10 py-2 text-sm border-gray-300 focus:outline-none focus:ring-primary focus:border-primary rounded-md">
                                    <option value="append">Append to Existing</option>
                                    <option value="replace">Replace Existing</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Price Adjustment Type</label>
                                <select id="priceAdjustmentType" class="mt-1 block w-full pl-3 pr-10 py-2 text-sm border-gray-300 focus:outline-none focus:ring-primary focus:border-primary rounded-md">
                                    <option value="percentage">Percentage</option>
                                    <option value="fixed">Fixed Amount</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Price Adjustment Value</label>
                                <div class="relative">
                                    <input type="number" id="priceAdjustmentValue" class="mt-1 block w-full pl-3 pr-10 py-2 text-sm border-gray-300 focus:outline-none focus:ring-primary focus:border-primary rounded-md" min="0" step="0.01">
                                    <span class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none" id="priceAdjustmentUnit">%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <form method="POST" class="mt-4 space-y-4">
                        <input type="hidden" name="provider_id" id="selectedProviderId">
                        <input type="hidden" name="profit_percent" id="selectedProfitPercent">
                        <input type="hidden" name="selected_services" id="selectedServices" value="">
                        <input type="hidden" name="import_mode" id="importModeValue">
                        <button type="submit" name="action" value="import_services" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                            Import Selected Services
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </main>

            <div class="pt-4">
                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                    Import Services
{{ ... }}
                </button>
            </div>
        </form>
    </div>

    <!-- Step 2: Select Category and Price Adjustment -->
    <div id="categoryStep" class="bg-white rounded-2xl shadow-lg p-6 mb-6 hidden">
        <h2 class="text-xl font-semibold mb-4">Step 2: Select Categories and Price Adjustment</h2>
        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Price Adjustment</label>
                <div class="flex space-x-4">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700">Type</label>
                        <select name="price_adjustment_type" id="priceAdjustmentType" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm rounded-md">
                            <option value="none">None</option>
                            <option value="percentage">Percentage</option>
                            <option value="amount">Fixed Amount</option>
                        </select>
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700">Value</label>
                        <div class="relative">
                            <input type="text" name="price_adjustment_value" id="priceAdjustmentValue" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm rounded-md" readonly>
                            <span class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none" id="priceAdjustmentUnit">%</span>
                        </div>
                        <div id="numericKeypad" class="hidden mt-2 bg-white rounded-lg shadow-lg p-4">
                            <div class="grid grid-cols-3 gap-2">
                                <button class="numeric-key w-full h-12 rounded-md bg-gray-100 hover:bg-gray-200">1</button>
                                <button class="numeric-key w-full h-12 rounded-md bg-gray-100 hover:bg-gray-200">2</button>
                                <button class="numeric-key w-full h-12 rounded-md bg-gray-100 hover:bg-gray-200">3</button>
                                <button class="numeric-key w-full h-12 rounded-md bg-gray-100 hover:bg-gray-200">4</button>
                                <button class="numeric-key w-full h-12 rounded-md bg-gray-100 hover:bg-gray-200">5</button>
                                <button class="numeric-key w-full h-12 rounded-md bg-gray-100 hover:bg-gray-200">6</button>
                                <button class="numeric-key w-full h-12 rounded-md bg-gray-100 hover:bg-gray-200">7</button>
                                <button class="numeric-key w-full h-12 rounded-md bg-gray-100 hover:bg-gray-200">8</button>
                                <button class="numeric-key w-full h-12 rounded-md bg-gray-100 hover:bg-gray-200">9</button>
                                <button class="numeric-key w-full h-12 rounded-md bg-gray-100 hover:bg-gray-200">.</button>
                                <button class="numeric-key w-full h-12 rounded-md bg-gray-100 hover:bg-gray-200">0</button>
                                <button class="numeric-key w-full h-12 rounded-md bg-red-100 hover:bg-red-200 text-red-600">Clear</button>
                                <button class="numeric-key w-full h-12 rounded-md bg-red-100 hover:bg-red-200 text-red-600">×</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Select Categories</label>
                <div id="categoryList" class="mt-2 space-y-2">
                    <!-- Categories will be populated via AJAX -->
                </div>
            </div>
            <button type="submit" name="action" value="fetch_services" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                Fetch Services
            </button>
        </form>
    </div>

    <!-- Step 3: Select Services -->
    <div id="serviceStep" class="bg-white rounded-2xl shadow-lg p-6 mb-6 hidden">
        <h2 class="text-xl font-semibold mb-4">Step 3: Select Services</h2>
        <div class="flex justify-between items-center mb-4">
            <span id="serviceCount">Total Services Found: 0</span>
            <button id="selectAllBtn" class="text-sm text-primary hover:text-primary-dark">
                Select All
            </button>
        </div>
        <div id="serviceList" class="overflow-x-auto">
            <!-- Services will be populated via AJAX -->
        </div>
        <div class="mt-4">
            <div class="flex justify-between items-center mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Select All Services</label>
                    <div class="flex items-center space-x-2">
                        <input type="checkbox" id="selectAllServices" class="h-4 w-4 text-primary focus:ring-primary">
                        <span id="selectedServicesCount">0</span> services selected
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <button type="button" id="selectAllBtn" class="text-sm text-primary hover:text-primary-dark">
                        Select All
                    </button>
                    <button type="button" id="deselectAllBtn" class="text-sm text-gray-500 hover:text-gray-700">
                        Deselect All
                    </button>
                </div>
            </div>
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center space-x-2">
                    <label class="text-sm font-medium text-gray-700">Show:</label>
                    <select id="filterType" class="mt-1 block w-32 pl-3 pr-10 py-2 text-sm border-gray-300 focus:outline-none focus:ring-primary focus:border-primary rounded-md">
                        <option value="all">All Services</option>
                        <option value="selected">Selected Services</option>
                        <option value="unselected">Unselected Services</option>
                    </select>
                </div>
                <div class="flex items-center space-x-2">
                    <label class="text-sm font-medium text-gray-700">Sort By:</label>
                    <select id="sortBy" class="mt-1 block w-32 pl-3 pr-10 py-2 text-sm border-gray-300 focus:outline-none focus:ring-primary focus:border-primary rounded-md">
                        <option value="name">Name</option>
                        <option value="price">Price</option>
                        <option value="category">Category</option>
                    </select>
                </div>
            </div>
        </div>
        <form method="POST" class="mt-4 space-y-4">
            <input type="hidden" name="provider_id" id="selectedProviderId">
            <input type="hidden" name="profit_percent" id="selectedProfitPercent">
            <input type="hidden" name="selected_services" id="selectedServices" value="">
            <button type="submit" name="action" value="import_services" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                Import Selected Services
            </button>
        </form>
    </div>
</div>
</main>
            // Handle price adjustment type change
            const priceAdjustmentType = document.getElementById('priceAdjustmentType');
            const priceAdjustmentValue = document.getElementById('priceAdjustmentValue');
            const priceAdjustmentUnit = document.getElementById('priceAdjustmentUnit');
            const numericKeypad = document.getElementById('numericKeypad');
            const numericKeys = document.querySelectorAll('.numeric-key');
            let currentValue = '';

            priceAdjustmentType.addEventListener('change', function() {
                const isNone = this.value === 'none';
                priceAdjustmentValue.value = '';
                currentValue = '';
                priceAdjustmentUnit.textContent = isNone ? '' : this.value === 'percentage' ? '%' : '₹';
                
                if (isNone) {
                    numericKeypad.classList.add('hidden');
                } else {
                    priceAdjustmentValue.classList.remove('hidden');
                }
            });

            // Initialize numeric keypad
            priceAdjustmentValue.addEventListener('focus', function() {
                if (priceAdjustmentType.value !== 'none') {
                    numericKeypad.classList.remove('hidden');
                }
            });

            priceAdjustmentValue.addEventListener('blur', function() {
                setTimeout(() => {
                    numericKeypad.classList.add('hidden');
                }, 200);
            });

            // Handle numeric keypad input
            numericKeys.forEach(key => {
                key.addEventListener('click', function() {
                    const value = this.textContent;
                    
                    if (value === '×') {
                        currentValue = '';
                    } else if (value === 'Clear') {
                        currentValue = '';
                    } else if (value === '.') {
                        if (!currentValue.includes('.')) {
                            currentValue += '.';
                        }
                    } else {
                        // Handle number input
                        if (currentValue === '0') {
                            currentValue = value;
                        } else {
                            currentValue += value;
                        }
                    }

                    // Format value
                    if (currentValue === '') {
                        priceAdjustmentValue.value = '';
                    } else {
                        const number = parseFloat(currentValue);
                        if (!isNaN(number)) {
                            priceAdjustmentValue.value = number.toFixed(2);
                        }
                    }
                });
            });

            // Handle keyboard input
            priceAdjustmentValue.addEventListener('input', function(e) {
                let value = e.target.value.replace(/[^\d.]/g, '');
                
                // Remove leading zeros
                if (value.startsWith('0') && value.length > 1 && value[1] !== '.') {
                    value = value.replace(/^0+/, '');
                }
                
                // Format value
                const number = parseFloat(value);
                if (!isNaN(number)) {
                    e.target.value = number.toFixed(2);
                    currentValue = number.toString();
                }
            });

            // Handle backspace
            priceAdjustmentValue.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace') {
                    currentValue = currentValue.slice(0, -1);
                }
            });

            // Handle form submission
            document.querySelector('form').addEventListener('submit', function(e) {
                if (priceAdjustmentType.value !== 'none' && !priceAdjustmentValue.value) {
                    e.preventDefault();
                    alert('Please enter a price adjustment value');
                    priceAdjustmentValue.focus();
                }
            });

            // Handle price adjustment value change
            function updateValueDisplay(value) {
                priceAdjustmentValue.value = value;
                if (value > 0) {
                    importButton.classList.remove('bg-gray-500');
                    importButton.classList.add('bg-primary');
                    importButton.textContent = 'Import Services';
                } else {
                    importButton.classList.remove('bg-primary');
                    importButton.classList.add('bg-gray-500');
                    importButton.textContent = 'Enter Adjustment Value';
                }
            }

            // Category Selection
            let selectedCategories = new Set();
            const categoryCheckboxes = document.querySelectorAll('.category-checkbox');
            const selectAllCategories = document.getElementById('selectAllCategories');
            const selectedCategoriesCount = document.getElementById('selectedCategoriesCount');

            categoryCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    if (this.checked) {
                        selectedCategories.add(this.value);
                    } else {
                        selectedCategories.delete(this.value);
                    }
                    updateCategoriesCount();
                });
            });

            selectAllCategories.addEventListener('change', function() {
                const isChecked = this.checked;
                categoryCheckboxes.forEach(checkbox => {
                    checkbox.checked = isChecked;
                    if (isChecked) {
                        selectedCategories.add(checkbox.value);
                    } else {
                        selectedCategories.delete(checkbox.value);
                    }
                });
                updateCategoriesCount();
            });
                    } else {
                        selectedServices.delete(this.value);
                    }
                    updateServicesCount();
                });
            });

            selectAllServices.addEventListener('change', function() {
                const isChecked = this.checked;
                serviceCheckboxes.forEach(checkbox => {
                    checkbox.checked = isChecked;
                    if (isChecked) {
                        selectedServices.add(checkbox.value);
                    } else {
                        selectedServices.delete(checkbox.value);
                    }
                });
                updateServicesCount();
            });

            selectAllBtn.addEventListener('click', function() {
                serviceCheckboxes.forEach(checkbox => {
                    checkbox.checked = true;
                    selectedServices.add(checkbox.value);
                });
                selectAllServices.checked = true;
                updateServicesCount();
            });

            deselectAllBtn.addEventListener('click', function() {
                serviceCheckboxes.forEach(checkbox => {
                    checkbox.checked = false;
                    selectedServices.delete(checkbox.value);
                });
                selectAllServices.checked = false;
                updateServicesCount();
            });

            function updateServicesCount() {
                selectedServicesCount.textContent = selectedServices.size;
            }

            // Filtering and Sorting
            const filterType = document.getElementById('filterType');
            const sortBy = document.getElementById('sortBy');
            const serviceRows = document.querySelectorAll('.service-row');

            filterType.addEventListener('change', filterServices);
            sortBy.addEventListener('change', sortServices);

            function filterServices() {
                const filter = filterType.value;
                serviceRows.forEach(row => {
                    const checkbox = row.querySelector('.service-checkbox');
                    if (filter === 'all') {
                        row.style.display = '';
                    } else if (filter === 'selected') {
                        row.style.display = checkbox.checked ? '' : 'none';
                    } else if (filter === 'unselected') {
                        row.style.display = checkbox.checked ? 'none' : '';
                    }
                });
            }

            function sortServices() {
                const sort = sortBy.value;
                const services = Array.from(serviceRows);
                services.sort((a, b) => {
                    const aVal = a.querySelector('.service-' + sort).textContent;
                    const bVal = b.querySelector('.service-' + sort).textContent;
                    return aVal.localeCompare(bVal);
                });
                services.forEach(service => serviceList.appendChild(service));
            }

            // Handle service list updates
            function updateServiceList(data) {
                serviceList.innerHTML = '';
                data.forEach(service => {
                    const row = document.createElement('div');
                    row.className = 'service-row flex items-center justify-between p-4 border-b border-gray-200';
                    row.innerHTML = `
                        <div class="flex items-center space-x-4">
                            <input type="checkbox" class="service-checkbox h-4 w-4 text-primary focus:ring-primary" value="${service.id}">
                            <div class="flex-1">
                                <div class="font-medium text-gray-900">${service.name}</div>
                                <div class="text-sm text-gray-500">Category: ${service.category}</div>
                                <div class="text-sm text-gray-500">Price: ${service.price}</div>
                            </div>
                        </div>
                        <div class="flex items-center space-x-4">
                            <button class="text-sm text-primary hover:text-primary-dark" onclick="editService(${service.id})">
                                Edit
                            </button>
                            <button class="text-sm text-red-500 hover:text-red-700" onclick="deleteService(${service.id})">
                                Delete
                            </button>
                        </div>
                    `;
                    serviceList.appendChild(row);
                });
                updateServicesCount();
                filterServices();
            }

            // AJAX functions
            function fetchCategories() {
                const providerId = document.getElementById('providerSelect').value;
                if (!providerId) return;

                fetch('bulk_import.php?action=fetch_categories&provider_id=' + providerId)
                    .then(response => response.json())
                    .then(data => {
                        const categoryList = document.getElementById('categoryList');
                        categoryList.innerHTML = '';
                        data.forEach(category => {
                            const checkbox = document.createElement('input');
                            checkbox.type = 'checkbox';
                            checkbox.className = 'category-checkbox';
                            checkbox.value = category.id;
                            checkbox.id = 'category-' + category.id;

                            const label = document.createElement('label');
                            label.htmlFor = 'category-' + category.id;
                            label.textContent = category.name;

                            const div = document.createElement('div');
                            div.className = 'flex items-center space-x-2';
                            div.appendChild(checkbox);
                            div.appendChild(label);
                            categoryList.appendChild(div);
                        });
                        updateCategoriesCount();
                    });
            }

            function fetchServices() {
                const providerId = document.getElementById('providerSelect').value;
                const selectedCategoryIds = Array.from(selectedCategories);
                
                fetch(`bulk_import.php?action=fetch_services&provider_id=${providerId}&categories=${selectedCategoryIds.join(',')}`)
                    .then(response => response.json())
                    .then(data => {
                        updateServiceList(data);
                    });
            }

            // Event listeners
            document.getElementById('providerSelect').addEventListener('change', fetchCategories);
            selectAllCategories.addEventListener('change', fetchServices);
            selectAllServices.addEventListener('change', updateServicesCount);

            // Initialize numeric keypad
            const keypad = document.getElementById('numericKeypad');
            const numericKeys = document.querySelectorAll('.numeric-key');
            let currentValue = '';

            // Show/hide keypad on input focus
            priceAdjustmentValue.addEventListener('focus', function() {
                keypad.classList.remove('hidden');
            });

            priceAdjustmentValue.addEventListener('blur', function() {
                setTimeout(() => {
                    keypad.classList.add('hidden');
                }, 100);
            });

            // Handle numeric key clicks
            numericKeys.forEach(key => {
                key.addEventListener('click', function() {
                    const value = this.textContent;
                    
                    if (value === '×') {
                        currentValue = '';
                    } else if (value === '.') {
                        if (!currentValue.includes('.')) {
                            currentValue += value;
                        }
                    } else if (value === 'Clear') {
                        currentValue = '';
                    } else {
                        currentValue += value;
                    }

                    // Convert to number and back to string to remove leading zeros
                    const numValue = parseFloat(currentValue);
                    if (!isNaN(numValue)) {
                        currentValue = numValue.toFixed(2);
                    } else {
                        currentValue = '';
                    }

                    updateValueDisplay(currentValue);
                });
            });

            // Handle keyboard input
            priceAdjustmentValue.addEventListener('keydown', function(e) {
                const allowedKeys = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.', 'Backspace'];
                if (!allowedKeys.includes(e.key)) {
                    e.preventDefault();
                    return;
                }

                if (e.key === 'Backspace') {
                    currentValue = currentValue.slice(0, -1);
                } else if (e.key === '.') {
                    if (!currentValue.includes('.')) {
                        currentValue += '.';
                    }
                } else {
                    currentValue += e.key;
                }

                // Convert to number and back to string to remove leading zeros
                const numValue = parseFloat(currentValue);
                if (!isNaN(numValue)) {
                    currentValue = numValue.toFixed(2);
                } else {
                    currentValue = '';
                }

                updateValueDisplay(currentValue);
            });

            // Handle provider selection
            providerSelect.addEventListener('change', function() {
                if (this.value) {
                    fetchCategories(this.value);
                    fetchProviderInfo(this.value);
                    fetchProviderStats(this.value);
                }
            });

            // Modal handling
            const providerInfoModal = document.getElementById('providerInfoModal');
            const providerStatsModal = document.getElementById('providerStatsModal');
            const modalCloseButtons = document.querySelectorAll('.modal-close');

            document.getElementById('providerInfoBtn').addEventListener('click', function() {
                const providerId = providerSelect.value;
                if (providerId) {
                    fetchProviderInfo(providerId);
                    providerInfoModal.classList.remove('hidden');
                }
            });

            document.getElementById('providerStatsBtn').addEventListener('click', function() {
                const providerId = providerSelect.value;
                if (providerId) {
                    fetchProviderStats(providerId);
                    providerStatsModal.classList.remove('hidden');
                }
            });

            modalCloseButtons.forEach(button => {
                button.addEventListener('click', function() {
                    providerInfoModal.classList.add('hidden');
                    providerStatsModal.classList.add('hidden');
                });
            });

            // Click outside modal to close
            window.addEventListener('click', function(event) {
                if (event.target === providerInfoModal) {
                    providerInfoModal.classList.add('hidden');
                }
                if (event.target === providerStatsModal) {
                    providerStatsModal.classList.add('hidden');
                }
            });

            // Fetch provider information
            async function fetchProviderInfo(providerId) {
                try {
                    const response = await fetch(`bulk_import.php?action=get_provider_info&provider_id=${providerId}`);
                    const data = await response.json();
                    
                    const content = document.getElementById('providerInfoContent');
                    content.innerHTML = `
                        <div class="space-y-4">
                            <div>
                                <h4 class="font-semibold">Provider Details</h4>
                                <p class="text-gray-600">${data.name}</p>
                                <p class="text-gray-600">${data.description}</p>
                            </div>
                            <div>
                                <h4 class="font-semibold">Contact Information</h4>
                                <p class="text-gray-600">Email: ${data.email}</p>
                                <p class="text-gray-600">Phone: ${data.phone}</p>
                            </div>
                            <div>
                                <h4 class="font-semibold">Status</h4>
                                <p class="text-gray-600">${data.status}</p>
                            </div>
                        </div>
                    `;
                } catch (error) {
                    console.error('Error fetching provider info:', error);
                }
            }

            // Fetch provider statistics
            async function fetchProviderStats(providerId) {
                try {
                    const response = await fetch(`bulk_import.php?action=get_provider_stats&provider_id=${providerId}`);
                    const data = await response.json();
                    
                    const content = document.getElementById('providerStatsContent');
                    content.innerHTML = `
                        <div class="space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <h4 class="font-semibold">Total Services</h4>
                                    <p class="text-2xl font-bold">${data.total_services}</p>
                                </div>
                                <div>
                                    <h4 class="font-semibold">Active Services</h4>
                                    <p class="text-2xl font-bold">${data.active_services}</p>
                                </div>
                                <div>
                                    <h4 class="font-semibold">Success Rate</h4>
                                    <p class="text-2xl font-bold">${data.success_rate}%</p>
                                </div>
                                <div>
                                    <h4 class="font-semibold">Average Response Time</h4>
                                    <p class="text-2xl font-bold">${data.avg_response_time}s</p>
                                </div>
                            </div>
                            <div>
                                <h4 class="font-semibold">Last 7 Days Performance</h4>
                                <canvas id="performanceChart"></canvas>
                            </div>
                        </div>
                    `;

                    // Initialize chart
                    const ctx = document.getElementById('performanceChart').getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.dates,
                            datasets: [{
                                label: 'Success Rate',
                                data: data.success_rates,
                                borderColor: '#4F46E5',
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                } catch (error) {
                    console.error('Error fetching provider stats:', error);
                }
            }
        });
    </script>
</body>
</html>

<?php
// Get providers
$stmt = $pdo->query("SELECT id, name FROM providers");
$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mx-auto px-4 py-8">
    <div class="bg-white rounded-2xl shadow-lg p-6">
        <h1 class="text-2xl font-bold mb-6">Bulk Import Services</h1>

        <!-- Step 1: Choose Provider -->
        <div id="providerStep" class="bg-white rounded-2xl shadow-lg p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">Step 1: Choose Provider</h2>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Provider</label>
                    <select name="provider_id" id="providerSelect" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm rounded-md" required>
                        <option value="">Select Provider</option>
                        <?php foreach ($providers as $provider): ?>
                            <option value="<?php echo $provider['id']; ?>"><?php echo htmlspecialchars($provider['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex justify-between items-center">
                    <div class="flex items-center space-x-4">
                        <button type="button" id="providerInfoBtn" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            Provider Info
                        </button>
                        <button type="button" id="providerStatsBtn" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                            Stats
                        </button>
                    </div>
                    <div>
                        <button type="submit" name="action" value="fetch_categories" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                            Next Step
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Provider Info Modal -->
        <div id="providerInfoModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                <div class="flex justify-between items-center pb-3">
                    <h3 class="text-xl font-bold">Provider Information</h3>
                    <button class="modal-close cursor-pointer z-50">
                        <svg class="fill-current text-black" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18">
                            <path d="M14.53 4.53l-1.06-1.06L9 7.94 4.53 3.47 3.47 4.53 7.94 9l-4.47 4.47 1.06 1.06L9 10.06l4.47 4.47 1.06-1.06L10.06 9z"></path>
                        </svg>
                    </button>
                </div>
                <div id="providerInfoContent" class="p-4">
                    <!-- Provider info will be populated via AJAX -->
                </div>
            </div>
        </div>

        <!-- Provider Stats Modal -->
        <div id="providerStatsModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                <div class="flex justify-between items-center pb-3">
                    <h3 class="text-xl font-bold">Provider Statistics</h3>
                    <button class="modal-close cursor-pointer z-50">
                        <svg class="fill-current text-black" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18">
                            <path d="M14.53 4.53l-1.06-1.06L9 7.94 4.53 3.47 3.47 4.53 7.94 9l-4.47 4.47 1.06 1.06L9 10.06l4.47 4.47 1.06-1.06L10.06 9z"></path>
                        </svg>
                    </button>
                </div>
                <div id="providerStatsContent" class="p-4">
                    <!-- Provider stats will be populated via AJAX -->
                </div>
            </div>
        </div>
    </div>
</div>
