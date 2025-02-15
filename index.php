<?php
session_start();
require_once "../../connection/connection.php";

// Check if user is logged in
if (!isset($_SESSION['email'])) {
    header("Location: sign-in.php");
    exit();
}

// Handle sign-out
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['sign_out'])) {
    session_destroy();
    header("Location: ../../sign-in/sign-in.php");
    exit();
}

// Current settings - Using specified values
$currentDateTime = date('Y-m-d H:i:s'); // Specified UTC datetime
$current_user = $_SESSION['email']; // Specified user login

// Helper functions
function formatNumber($number) {
    return number_format(floatval($number), 2, '.', ',');
}

function getCartTotal($cart) {
    return array_reduce($cart, function ($carry, $item) {
        return $carry + (floatval($item['price']) * intval($item['quantity']));
    }, 0);
}

// Get company_id from login table
$stmt = $con->prepare("SELECT l.company_id, c.company_name 
                      FROM login l 
                      JOIN companies c ON l.company_id = c.id 
                      WHERE l.email = ?");
$stmt->bind_param("s", $_SESSION['email']);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user_data) {
    session_destroy();
    header("Location: sign-in.php?error=invalid_session");
    exit();
}

$company_id = $user_data['company_id'];
$company_name = $user_data['company_name'];

// Initialize cart if not set
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
?>
<?php
// Handle barcode search
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['search_barcode'])) {
    $barcode = trim($_POST['barcode']);
    
    $stmt = $con->prepare("SELECT id, product_name, sale_price, quantity, barcode, image_path 
                          FROM products 
                          WHERE barcode = ? AND company_id = ?");
    
    if (!$stmt) {
        error_log("Prepare failed: " . $con->error);
        $_SESSION['error'] = "Database error";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    $stmt->bind_param("si", $barcode, $company_id);
    
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        $_SESSION['error'] = "Query failed";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    $result = $stmt->get_result();
    $product = $result->fetch_assoc();
    $stmt->close();

    if ($product) {
        $quantity = 1;
        
        // Check stock availability
        if ($product['quantity'] < $quantity) {
            $_SESSION['error'] = "Insufficient stock available";
        } else {
            $found = false;
            foreach ($_SESSION['cart'] as &$item) {
                if ($item['product_id'] == $product['id']) {
                    if (($item['quantity'] + $quantity) <= $product['quantity']) {
                        $item['quantity'] += $quantity;
                        $item['total'] = $item['price'] * $item['quantity'];
                        $found = true;
                    } else {
                        $_SESSION['error'] = "Insufficient stock available";
                    }
                    break;
                }
            }
            
            if (!$found && !isset($_SESSION['error'])) {
                $_SESSION['cart'][] = [
                    'product_id' => $product['id'],
                    'name' => $product['product_name'],
                    'price' => floatval($product['sale_price']),
                    'quantity' => $quantity,
                    'total' => floatval($product['sale_price']) * $quantity,
                    'barcode' => $product['barcode'],
                    'image_path' => $product['image_path'] ?? ''
                ];
                $_SESSION['message'] = "Product added successfully";
            }
        }
    } else {
        $_SESSION['error'] = "Product not found";
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . '?focus=true');
    exit();
}

// Add to Cart Process
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_to_cart'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);

    $stmt = $con->prepare("SELECT id, product_name, sale_price, quantity, barcode, image_path 
                          FROM products WHERE id = ? AND company_id = ?");
    $stmt->bind_param("ii", $product_id, $company_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($product) {
        $current_cart_quantity = 0;
        foreach ($_SESSION['cart'] as $item) {
            if ($item['product_id'] == $product_id) {
                $current_cart_quantity = $item['quantity'];
                break;
            }
        }

        $total_requested_quantity = $current_cart_quantity + $quantity;

        if ($total_requested_quantity > $product['quantity']) {
            $_SESSION['error'] = "Not enough stock. Available: " . $product['quantity'];
        } else {
            $found = false;
            foreach ($_SESSION['cart'] as &$item) {
                if ($item['product_id'] == $product_id) {
                    $item['quantity'] += $quantity;
                    $item['total'] = $item['price'] * $item['quantity'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $_SESSION['cart'][] = [
                    'product_id' => $product['id'],
                    'name' => $product['product_name'],
                    'price' => floatval($product['sale_price']),
                    'quantity' => $quantity,
                    'total' => floatval($product['sale_price']) * $quantity,
                    'barcode' => $product['barcode'],
                    'image_path' => $product['image_path'] ?? ''
                ];
            }
            $_SESSION['message'] = "Product added successfully";
        }
    } else {
        $_SESSION['error'] = "Product not found";
    }
    header("Location: " . $_SERVER['PHP_SELF'] . '?focus=true');
    exit();
}
?>
<?php
// Update Cart Process
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_cart'])) {
    if (isset($_POST['product_id']) && isset($_POST['quantity'])) {
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);

        $stmt = $con->prepare("SELECT quantity FROM products WHERE id = ? AND company_id = ?");
        $stmt->bind_param("ii", $product_id, $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();
        $stmt->close();

        if ($product && $quantity <= $product['quantity']) {
            foreach ($_SESSION['cart'] as &$item) {
                if ($item['product_id'] == $product_id) {
                    $item['quantity'] = $quantity;
                    $item['total'] = $item['price'] * $quantity;
                    break;
                }
            }
            
            echo json_encode([
                'status' => 'success',
                'total' => getCartTotal($_SESSION['cart']),
                'formatted_total' => formatNumber(getCartTotal($_SESSION['cart']))
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Insufficient stock'
            ]);
        }
        exit();
    }
}

// Clear Cart Process
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['clear_cart'])) {
    $_SESSION['cart'] = [];
    echo json_encode(['status' => 'success']);
    exit();
}

// Remove Item Process
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['remove_item'])) {
    $index = isset($_POST['index']) ? intval($_POST['index']) : -1;
    if ($index >= 0 && isset($_SESSION['cart'][$index])) {
        array_splice($_SESSION['cart'], $index, 1);
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Process Transaction
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['process_transaction'])) {
    if (!empty($_SESSION['cart'])) {
        $total = getCartTotal($_SESSION['cart']);
        $current_datetime = "2025-02-02 04:59:09"; // Updated datetime
        
        $con->begin_transaction();
        try {
            // Verify stock availability before processing
            foreach ($_SESSION['cart'] as $item) {
                $stmt = $con->prepare("SELECT quantity FROM products WHERE id = ? AND company_id = ?");
                $stmt->bind_param("ii", $item['product_id'], $company_id);
                $stmt->execute();
                $product = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$product || $product['quantity'] < $item['quantity']) {
                    throw new Exception("Insufficient stock for " . $item['name']);
                }
            }

            // Insert transaction
            $stmt = $con->prepare("INSERT INTO transactions (total, company_id, created_at) VALUES (?, ?, ?)");
            $stmt->bind_param("dis", $total, $company_id, $current_datetime);
            $stmt->execute();
            $transaction_id = $stmt->insert_id;
            $stmt->close();

            // Insert items and update stock
            foreach ($_SESSION['cart'] as $item) {
                $stmt = $con->prepare("INSERT INTO transaction_items (transaction_id, product_id, quantity, price) 
                                     VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iiid", $transaction_id, $item['product_id'], $item['quantity'], $item['price']);
                $stmt->execute();
                $stmt->close();

                // Update product stock
                $stmt = $con->prepare("UPDATE products 
                                     SET quantity = quantity - ? 
                                     WHERE id = ? AND company_id = ? AND quantity >= ?");
                $stmt->bind_param("iiii", $item['quantity'], $item['product_id'], $company_id, $item['quantity']);
                $stmt->execute();
                $stmt->close();
            }

            $con->commit();
            echo json_encode([
                'status' => 'success',
                'transaction_id' => $transaction_id,
                'total' => $total,
                'formatted_total' => formatNumber($total)
            ]);
        } catch (Exception $e) {
            $con->rollback();
            error_log("Transaction failed: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Cart is empty']);
    }
    exit();
}
?>
<?php
// Handle session messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Fetch products for dropdown
$stmt = $con->prepare("SELECT id, product_name, sale_price, quantity, barcode, image_path 
                      FROM products 
                      WHERE company_id = ? 
                      ORDER BY product_name ASC");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<?php
// Current settings
$date = new DateTime(); // Create a new DateTime object with the current date and time
$date->modify('+5 hours'); // Modify the date and time by adding 5 hours
$current_datetime= $date->format('Y-m-d H:i'); // Format the date and time and print it
$current_user = $_SESSION['email']; // Current user login
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS System - <?= htmlspecialchars($company_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        /* General Styles */
        body { 
            background-color: #f8f9fa; 
            font-family: Arial, sans-serif;
        }
        
        /* Receipt Print Styles */
        @media print {
    /* Ensure exact color reproduction */
    * {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* Hide everything except the receipt */
    body * {
        visibility: hidden;
    }

    .receipt, .receipt * {
        visibility: visible;
        color: #000 !important;           /* Force pure black text */
        font-weight: 600 !important;       /* Increase font weight */
    }

    .receipt {
        position: absolute;
        left: 50%;
        top: 0;
        width: 80mm; /* Set the width of the receipt */
        height: 297mm; /* Set the height of the receipt */
        margin: 0;
        padding: 0;
        transform: translateX(-50%); /* Center align the receipt */
        text-shadow: 0 0 1px #000; /* Subtle shadow to enhance perceived darkness */
    }

    #qrcode-container {
        display: flex;
        justify-content: center;
    }

    html, body {
        margin: 0;
        padding: 0;
    }
}

            @page {
                size: 96mm auto;
                margin: 0;
            }
            #receipt {
                page-break-after: avoid;
                page-break-before: avoid;
            }
            .receipt-header {
                font-size: 16px;
                font-weight: bold;
            }
            .receipt-items {
                font-size: 14px;
                line-height: 1.5;
            }
            .receipt-total {
                font-size: 16px;
                font-weight: bold;
            }
        
        
        /* Custom Styles */
        .container-fluid { 
            padding: 20px; 
        }
        .card { 
            margin-bottom: 20px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
        }
        .product-image { 
            max-width: 50px; 
            max-height: 50px; 
            object-fit: contain; 
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Header -->
        <div class="card mb-3">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h4 class="mb-0"><?= htmlspecialchars($company_name) ?></h4>
                <small class="text-muted">
                    <i class="fas fa-clock"></i> <?= $current_datetime ?>
                </small>
                <a href="../dashboard.php" class="btn btn-primary btn-sm ml-2">
                    <i class="fas fa-home"></i> Home
                </a>
            </div>
            <div class="col-md-6 text-right">
                <small class="text-muted">User: <?= htmlspecialchars($current_user) ?></small>
                <form method="POST" style="display: inline;">
                    <button type="submit" name="sign_out" class="btn btn-danger btn-sm ml-2">
                        <i class="fas fa-sign-out-alt"></i> Sign Out
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

        <!-- Alert Messages -->
        <?php if(isset($message)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        <?php endif; ?>

        <?php if(isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= htmlspecialchars($error) ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        <?php endif; ?>
        <div class="row">
            <!-- Products Section -->
            <div class="col-md-5">
                <!-- Barcode Scanner -->
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white">
                        <i class="fas fa-barcode"></i> Scan Barcode
                    </div>
                    <div class="card-body">
                        <form method="POST" id="barcode-form" autocomplete="off">
                            <div class="input-group">
                                <input type="text" name="barcode" class="form-control form-control-lg" 
                                       placeholder="Scan barcode" autofocus autocomplete="off">
                                <div class="input-group-append">
                                    <button type="submit" name="search_barcode" class="btn btn-primary">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Product Selection -->
                <div class="card">
    <div class="card-header bg-info text-white">
        <i class="fas fa-shopping-cart"></i> Add Products
    </div>
    <div class="card-body">
        <form method="POST" id="add-product-form">
            <div class="form-group">
                <label>Select Product</label>
                <select name="product_id" class="form-control select2" required>
                    <option value="">-- Select Product --</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= $product['id'] ?>"
                                data-price="<?= $product['sale_price'] ?>"
                                data-stock="<?= $product['quantity'] ?>"
                                data-image="<?= htmlspecialchars($product['image_path']) ?>"
                                <?= ($product['quantity'] <= 0 ? 'disabled' : '') ?>>
                            <?= htmlspecialchars($product['product_name']) ?>
                            (Rs.<?= formatNumber($product['sale_price']) ?>)
                            - Stock: <?= $product['quantity'] ?>
                            - Barcode: <?= htmlspecialchars($product['barcode']) ?>
                            <?= ($product['quantity'] <= 0 ? ' (Out of Stock)' : '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($products)): ?>
                <div id="product-preview" class="text-center mb-3">
                    <img src="<?= htmlspecialchars($products[0]['image_path']) ?>" alt="Product Preview" class="product-image img-thumbnail">
                    <div class="mt-2">
                        <span class="badge badge-info">Available Stock: <span id="stock-display"><?= $products[0]['quantity'] ?></span></span>
                    </div>
                    <div class="mt-2">
                        <span class="badge badge-secondary">Barcode: <span id="barcode-display"><?= htmlspecialchars($products[0]['barcode']) ?></span></span>
                    </div>
                </div>
            <?php endif; ?>
            <div class="form-group">
                <label>Quantity</label>
                <input type="number" name="quantity" class="form-control" value="1" min="1" required>
            </div>
            <button type="submit" name="add_to_cart" class="btn btn-primary btn-block">
                <i class="fas fa-cart-plus"></i> Add to Cart
            </button>
        </form>
    </div>
</div>
            </div>
                        <!-- Cart Section -->
                        <div class="col-md-7">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <i class="fas fa-shopping-basket"></i> Shopping Cart
                    </div>
                    <div class="card-body">
                        <?php if(empty($_SESSION['cart'])): ?>
                            <div class="text-center text-muted my-5">
                                <i class="fas fa-shopping-cart fa-3x mb-3"></i>
                                <p>Cart is empty</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="thead-light">
                                        <tr>
                                            <th style="width: 80px;">Image</th>
                                            <th>Product</th>
                                            <th class="text-right">Price</th>
                                            <th style="width: 120px;">Quantity</th>
                                            <th class="text-right">Total</th>
                                            <th style="width: 50px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($_SESSION['cart'] as $index => $item): ?>
                                            <tr>
                                                <td class="text-center">
                                                    <?php if (!empty($item['image_path'])): ?>
                                                        <img src="<?= htmlspecialchars($item['image_path']) ?>" 
                                                             alt="<?= htmlspecialchars($item['name']) ?>"
                                                             class="product-image">
                                                    <?php else: ?>
                                                        <i class="fas fa-box fa-2x text-muted"></i>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($item['name']) ?></td>
                                                <td class="text-right">Rs.<?= formatNumber($item['price']) ?></td>
                                                <td>
                                                    <input type="number" 
                                                           value="<?= $item['quantity'] ?>" 
                                                           min="1" 
                                                           class="form-control update-quantity" 
                                                           data-product-id="<?= $item['product_id'] ?>"
                                                           data-original-quantity="<?= $item['quantity'] ?>">
                                                </td>
                                                <td class="text-right">Rs.<?= formatNumber($item['total']) ?></td>
                                                <td class="text-center">
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="index" value="<?= $index ?>">
                                                        <button type="submit" 
                                                                name="remove_item" 
                                                                class="btn btn-sm btn-danger"
                                                                title="Remove Item">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-info">
                                            <td colspan="4" class="text-right"><strong>Total:</strong></td>
                                            <td class="text-right" colspan="2">
                                                <strong>Rs.<span id="cart-total">
                                                    <?= formatNumber(getCartTotal($_SESSION['cart'])) ?>
                                                </span></strong>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
<!-- Payment Section -->
<div class="card mt-4 shadow-sm">
  <div class="card-header bg-primary text-white">
    <h5 class="mb-0">Payment Details</h5>
  </div>
  <div class="card-body">
    <form id="payment-form">
      <!-- Hidden Original Total (using raw numeric value) -->
      <input type="hidden" id="original-total" value="<?= getCartTotal($_SESSION['cart']) ?>">
      <div class="row">
        <!-- Left Column -->
        <div class="col-md-6">
          <!-- Total Amount (Final Total) -->
          <div class="form-group">
            <label for="total-amount"><strong>Total Amount</strong></label>
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text">Rs.</span>
              </div>
              <input
                type="text"
                id="total-amount"
                class="form-control"
                value="<?= getCartTotal($_SESSION['cart']) ?>"
                readonly
              >
            </div>
          </div>
          <!-- Discount Amount -->
          <div class="form-group">
            <label for="discount-amount"><strong>Discount Amount</strong></label>
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text">Rs.</span>
              </div>
              <input
                type="number"
                id="discount-amount"
                class="form-control"
                step="0.01"
                min="0"
                placeholder="Enter discount"
              >
            </div>
          </div>
          <!-- Discount Percentage -->
          <div class="form-group">
            <label for="discount-percentage"><strong>Discount (%)</strong></label>
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text">%</span>
              </div>
              <input
                type="number"
                id="discount-percentage"
                class="form-control"
                step="0.01"
                min="0"
                placeholder="Discount %"
              >
            </div>
            <small class="form-text text-muted">
              Enter a percentage value (e.g., 10 for 10%).
            </small>
          </div>
        </div>
        <!-- Right Column -->
        <div class="col-md-6">
          <!-- Fixed Service Charges -->
          <div class="form-group">
            <label for="extra-charges"><strong>Service Charges (Fixed)</strong></label>
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text">Rs.</span>
              </div>
              <input
                type="number"
                id="extra-charges"
                class="form-control"
                step="0.01"
                min="0"
                placeholder="Fixed charges"
              >
            </div>
          </div>
          <!-- Percentage Service Charges -->
          <div class="form-group">
            <label for="extra-charges-percentage"><strong>Service Charges (%)</strong></label>
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text">%</span>
              </div>
              <input
                type="number"
                id="extra-charges-percentage"
                class="form-control"
                step="0.01"
                min="0"
                placeholder="Extra %"
              >
            </div>
            <small class="form-text text-muted">
              Enter a percentage value (e.g., 10 for 10%).
            </small>
          </div>
        </div>
      </div>
      <!-- Second Row: Amount Received and Change Due -->
      <div class="row">
        <div class="col-md-6">
          <!-- Amount Received -->
          <div class="form-group">
            <label for="amount-received"><strong>Amount Received</strong></label>
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text">Rs.</span>
              </div>
              <input
                type="number"
                id="amount-received"
                class="form-control"
                step="0.01"
                min="0"
                placeholder="Enter amount received"
              >
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <!-- Change Due -->
          <div class="form-group">
            <label for="change-amount"><strong>Change Due</strong></label>
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text">Rs.</span>
              </div>
              <input
                type="text"
                id="change-amount"
                class="form-control"
                readonly
                value="0.00"
              >
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
  <!-- Consolidated Action Buttons -->
  <div class="card-footer d-flex justify-content-between">
    <button id="clear-cart" class="btn btn-warning btn-lg">
      <i class="fas fa-trash"></i> Clear Cart
    </button>
    <button id="process-transaction" class="btn btn-success btn-lg" disabled>
      <i class="fas fa-check-circle"></i> Complete Sale
    </button>
  </div>
</div>

<script>
  // Retrieve original total from the hidden field (raw numeric value)
  const originalTotal = parseFloat(document.getElementById('original-total').value) || 0;

  // Calculate the final total amount based on inputs
  function updateFinalTotal() {
    const discountAmount = parseFloat(document.getElementById('discount-amount').value) || 0;
    const discountPercentage = parseFloat(document.getElementById('discount-percentage').value) || 0;
    const discount = discountAmount + (originalTotal * (discountPercentage / 100));
    const fixedCharges = parseFloat(document.getElementById('extra-charges').value) || 0;
    const percentageCharges = parseFloat(document.getElementById('extra-charges-percentage').value) || 0;
    const percentageChargeAmount = originalTotal * (percentageCharges / 100);
    const finalTotal = originalTotal - discount + fixedCharges + percentageChargeAmount;
    document.getElementById('total-amount').value = finalTotal.toFixed(2);
    return finalTotal;
  }

  // Update change due and enable/disable Complete Sale button accordingly
  function updateChangeDue() {
    const finalTotal = updateFinalTotal();
    const amountReceived = parseFloat(document.getElementById('amount-received').value) || 0;
    const change = amountReceived - finalTotal;
    document.getElementById('change-amount').value = change.toFixed(2);
    // Enable Complete Sale if amount received covers the final total and is not zero
    document.getElementById('process-transaction').disabled = (change < 0 || amountReceived === 0);
  }

  // Attach event listeners to update values as the user types
  document.getElementById('discount-amount').addEventListener('input', updateChangeDue);
  document.getElementById('discount-percentage').addEventListener('input', updateChangeDue);
  document.getElementById('extra-charges').addEventListener('input', updateChangeDue);
  document.getElementById('extra-charges-percentage').addEventListener('input', updateChangeDue);
  document.getElementById('amount-received').addEventListener('input', updateChangeDue);

  // Initial update call to set the proper state on page load
  updateChangeDue();

  // Sample event listener for the Complete Sale button
  document.getElementById('process-transaction').addEventListener('click', function(e) {
    e.preventDefault();
    // Replace this alert with your actual sale processing logic
    alert('Sale completed successfully!');
  });
</script>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
                <!-- Receipt Template -->
        <!-- Include a print-specific style block -->

        <meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt</title>
<link rel="stylesheet" href="print-receipt.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<div class="receipt" id="receipt" style="display: none;">
    <div style="width: 80mm; height: 297mm; margin: 0 auto; padding: 10px; box-sizing: border-box;">
        <!-- Receipt Header -->
        <div style="text-align: center; margin-bottom: 20px;">
            <h3 style="font-size: 20px; margin: 0; font-weight: bold;">
                <?= htmlspecialchars($company_name) ?>
            </h3>
            <div style="font-size: 16px; margin: 10px 0;">Sales Receipt</div>
            <div style="font-size: 14px;">
                <div>Date: <?= $current_datetime ?></div>
                <div>Transaction #: <span id="receipt-transaction-id"></span></div>
                <div id="qrcode-container" style="margin-top: 10px; display: flex; justify-content: center;">
                    <div id="qrcode"></div>
                </div>
            </div>
            <div style="font-size: 14px; margin-top: 5px;">
                Served by: <?= htmlspecialchars($current_user) ?>
            </div>
        </div>
        
        <!-- Receipt Items -->
        <div style="border-top: 1px dashed #000; border-bottom: 1px dashed #000; margin: 10px 0; padding: 10px 0;">
            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                <thead>
                    <tr style="border-bottom: 1px solid #000;">
                        <th style="text-align: left; padding: 5px 0;">Item</th>
                        <th style="text-align: center; padding: 5px 0;">Qty</th>
                        <th style="text-align: right; padding: 5px 0;">Price (Rs.)</th>
                        <th style="text-align: right; padding: 5px 0;">Total</th>
                    </tr>
                </thead>
                <tbody id="receipt-items"></tbody>
            </table>
        </div>
        
        <!-- Receipt Totals -->
        <div style="font-size: 14px; margin-top: 10px;">
            <div style="display: flex; justify-content: space-between; margin: 5px 0;">
                <strong>Total Amount:</strong>
                <span>Rs.<span id="receipt-total"></span></span>
            </div>
            <div style="display: flex; justify-content: space-between; margin: 5px 0;">
                <strong>Discount:</strong>
                <span>Rs.<span id="receipt-discount"></span></span>
            </div>
            <div style="display: flex; justify-content: space-between; margin: 5px 0;">
                <strong>Service Charges:</strong>
                <span>Rs.<span id="receipt-service-charges"></span></span>
            </div>
            <div style="display: flex; justify-content: space-between; margin: 5px 0;">
                <strong>Net Total:</strong>
                <span>Rs.<span id="receipt-net-total"></span></span>
            </div>
            <div style="display: flex; justify-content: space-between; margin: 5px 0;">
                <strong>Amount Received:</strong>
                <span>Rs.<span id="receipt-received"></span></span>
            </div>
            <div style="display: flex; justify-content: space-between; margin: 5px 0; font-size: 16px; font-weight: bold;">
                <strong>Change:</strong>
                <span>Rs.<span id="receipt-change"></span></span>
            </div>
        </div>
        
        <!-- Receipt Footer -->
        <div style="text-align: center; margin-top: 20px; padding-top: 10px; border-top: 1px dashed #000;">
            <p style="margin: 5px 0; font-size: 14px;">Thank you for your business!</p>
            <div style="font-size: 14px; margin-top: 5px;">
                Software Powered by: 0322-0593033
            </div>
        </div>
    </div>
</div>

<?php
// Function to save the transaction
function saveTransaction($total, $discount, $service_charges, $net_total, $user_id) {
    global $con;
    
    $stmt = $con->prepare("INSERT INTO transactions (total, discount, service_charges, net_total, user_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ddddd", $total, $discount, $service_charges, $net_total, $user_id);
    $stmt->execute();
    $transaction_id = $stmt->insert_id;
    $stmt->close();
    
    return $transaction_id;
}

// Handle transaction processing
if (isset($_POST['process_transaction'])) {
    $total = $_POST['total'];
    $discount = $_POST['discount'];
    $service_charges = $_POST['service_charges'];
    $net_total = $_POST['net_total'];
    $user_id = $_SESSION['user_id'];
    
    $transaction_id = saveTransaction($total, $discount, $service_charges, $net_total, $user_id);
    
    echo json_encode(['status' => 'success', 'transaction_id' => $transaction_id, 'formatted_total' => number_format($net_total, 2)]);
    exit;
}
?>

<script>
    function generateQRCode(transactionId) {
        new QRCode(document.getElementById("qrcode"), {
            text: transactionId,
            width: 100,
            height: 100,
            correctLevel: QRCode.CorrectLevel.L // Lower the error correction level to simplify the QR code
        });
    }

    function setTransactionId(transactionId) {
        document.getElementById("receipt-transaction-id").innerText = transactionId;
        generateQRCode(transactionId);
    }

    // Simulate setting the transaction ID after the sale is done
    document.addEventListener("DOMContentLoaded", function() {
        // Simulate getting the transaction ID from the server or after the sale is done
        setTimeout(function() {
            const transactionId = "EasyPaisa"; // This should be dynamically set after the sale
            setTransactionId(transactionId);
        }, 1000); // Adjust the delay as needed
    });
</script>

    </div>
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
    $(document).ready(function() {
        // Initialize Select2
        $('.select2').select2({
            templateResult: formatProduct,
            templateSelection: formatProduct
        });

        // Product option formatting
        function formatProduct(product) {
            if (!product.id) return product.text;
            
            const $product = $(product.element);
            const imagePath = $product.data('image');
            
            return $(`
                <div class="d-flex align-items-center">
                    <img src="${imagePath}" class="product-image mr-2">
                    <span>${product.text}</span>
                </div>
            `);
        }

        // Product selection handling
        $('select[name="product_id"]').on('change', function() {
            const selected = $(this).find(':selected');
            const imagePath = selected.data('image');
            const stock = parseInt(selected.data('stock')) || 0;
            const price = parseFloat(selected.data('price')) || 0;
            const barcode = selected.data('barcode');
            
            // Update product preview
            if (imagePath) {
                $('#product-preview img').attr('src', imagePath);
                $('#product-preview').show();
            } else {
                $('#product-preview').hide();
            }
            
            // Update stock and barcode display
            $('#stock-display').text(stock);
            $('#barcode-display').text(barcode);
            
            // Set quantity limits
            const quantityInput = $('input[name="quantity"]');
            quantityInput.attr('max', stock);
            
            if (stock <= 0) {
                quantityInput.val(0);
                $('button[name="add_to_cart"]').prop('disabled', true);
            } else {
                quantityInput.val(1);
                $('button[name="add_to_cart"]').prop('disabled', false);
                // Focus on quantity input after selecting product
                setTimeout(() => {
                    quantityInput.focus().select();
                }, 100);
            }
        });

        // Barcode form submission
        $('#barcode-form').on('submit', function(e) {
            if ($('input[name="barcode"]').val().trim()) {
                return true; // Allow form submission if barcode is not empty
            }
            e.preventDefault();
            return false;
        });

        // Quantity validation with enter key support
        $('input[name="quantity"]').on('keypress', function(e) {
            if (e.which === 13) { // Enter key pressed
                e.preventDefault();
                if (!$('button[name="add_to_cart"]').prop('disabled')) {
                    // Trigger the submit button click to include 'add_to_cart' in POST data
                    $('button[name="add_to_cart"]').trigger('click');
                }
            }
        }).on('input', function() {
            const max = parseInt($(this).attr('max')) || 0;
            let val = parseInt($(this).val()) || 0;
            
            if (val > max) {
                $(this).val(max);
                val = max;
            }
            
            if (val < 1) {
                $(this).val(1);
                val = 1;
            }
        });

        // Cart quantity updates
        $('.update-quantity').on('change', function() {
            const productId = $(this).data('product-id');
            const quantity = parseInt($(this).val()) || 1;
            const originalQuantity = parseInt($(this).data('original-quantity'));
            
            if (quantity < 1) {
                $(this).val(originalQuantity);
                return;
            }

            $.post('', {
                update_cart: true,
                product_id: productId,
                quantity: quantity
            }, function(response) {
                const data = JSON.parse(response);
                if (data.status === 'success') {
                    $('#cart-total').text(data.formatted_total);
                    $('#total-amount').val(data.formatted_total);
                    $('#amount-received').val('').trigger('input');
                } else {
                    alert(data.message);
                    window.location.reload();
                }
            });
        });

        // Clear cart confirmation
        $('#clear-cart').on('click', function() {
            if (confirm('Are you sure you want to clear the cart?')) {
                $.post('', { clear_cart: true }, function() {
                    window.location.reload();
                });
            }
        });

        // Process transaction
        $('#process-transaction').on('click', function() {
            if ($(this).prop('disabled')) return;

            // Retrieve and parse amounts
            const totalAmount = parseFloat($('#total-amount').val().replace(/,/g, ''));
            const received = parseFloat($('#amount-received').val());
            const change = received - totalAmount;
            
            // Calculate discount and service charges from the form values
            const discountAmount = parseFloat($('#discount-amount').val()) || 0;
            const discountPercentage = parseFloat($('#discount-percentage').val()) || 0;
            const discount = discountAmount + (originalTotal * (discountPercentage / 100));
            const fixedCharges = parseFloat($('#extra-charges').val()) || 0;
            const percentageCharges = parseFloat($('#extra-charges-percentage').val()) || 0;
            const percentageChargeAmount = originalTotal * (percentageCharges / 100);
            const totalServiceCharges = fixedCharges + percentageChargeAmount;
            const netTotal = originalTotal - discount + totalServiceCharges;

            $.post('', { process_transaction: true, discount: discount, service_charges: totalServiceCharges, net_total: netTotal, total: totalAmount }, function(response) {
                const data = JSON.parse(response);
                if (data.status === 'success') {
                    // Update receipt transaction details
                    $('#receipt-transaction-id').text(data.transaction_id);
                    $('#receipt-total').text(data.formatted_total);
                    
                    // Display discount and service charges under the total amount
                    $('#receipt-discount').text(discount.toFixed(2));
                    $('#receipt-service-charges').text(totalServiceCharges.toFixed(2));
                    $('#receipt-net-total').text(netTotal.toFixed(2));

                    $('#receipt-received').text(received.toFixed(2));
                    $('#receipt-change').text(change.toFixed(2));

                    // Generate receipt items from the cart
                    let receiptItems = '';
                    <?php foreach ($_SESSION['cart'] as $item): ?>
                    receiptItems += `
                        <tr>
                            <td style="text-align: left; padding: 3px 0;">
                                <?= htmlspecialchars($item['name']) ?>
                            </td>
                            <td style="text-align: center; padding: 3px 0;">
                                <?= $item['quantity'] ?>
                            </td>
                            <td style="text-align: right; padding: 3px 0;">
                                <?= formatNumber($item['price']) ?>
                            </td>
                            <td style="text-align: right; padding: 3px 0;">
                                <?= formatNumber($item['total']) ?>
                            </td>
                        </tr>
                    `;
                    <?php endforeach; ?>
                    $('#receipt-items').html(receiptItems);

                    // Show and print the receipt
                    $('#receipt').show();
                    setTimeout(function() {
                        window.print();
                        setTimeout(function() {
                            $.post('', { clear_cart: true }, function() {
                                window.location.reload();
                            });
                        }, 500);
                    }, 500);
                } else {
                    alert(data.message || 'Transaction failed');
                }
            }).fail(function() {
                alert('Network error occurred. Please try again.');
            });
        });

        // Auto-hide alerts
        $('.alert').delay(4000).fadeOut(350);

        // Barcode input focus
        function focusBarcodeInput() {
            $('input[name="barcode"]').focus();
        }

        // Keep focus on barcode input
        focusBarcodeInput();
        $(document).on('click', function(e) {
            if (!$(e.target).is('input, select, textarea, button, .select2, .select2 *')) {
                focusBarcodeInput();
            }
        });

        // Enter key on amount received
        $('#amount-received').on('keypress', function(e) {
            if (e.which === 13 && !$('#process-transaction').prop('disabled')) {
                e.preventDefault();
                $('#process-transaction').click();
            }
        });

        // Handle form submission after adding to cart
        $('#add-product-form').on('submit', function() {
            setTimeout(() => {
                $('.select2').val('').trigger('change');
                focusBarcodeInput();
            }, 100);
        });

        // Allow enter key submission for barcode
        $('input[name="barcode"]').on('keypress', function(e) {
            if (e.which === 13 && $(this).val().trim()) {
                $('#barcode-form button[name="search_barcode"]').click();
            }
        });
    });
    </script>
</body>
</html>
