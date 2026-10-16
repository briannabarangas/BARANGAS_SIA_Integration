<?php
require __DIR__ . '/config.php';

function finish(string $title, string $message): never {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title><body style="font:16px Arial;max-width:800px;margin:40px auto">';
    echo '<h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p><p><a href="index.php">Store</a> | <a href="cart.php">Cart</a> | <a href="orders.php">Orders</a></p></body></html>';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: cart.php'); exit; }

$customer = trim((string)($_POST['customer_name'] ?? ''));
$method = (string)($_POST['payment_method'] ?? 'CARD');
if ($customer === '') finish('Missing information', 'Enter a customer name.');
if (!in_array($method, ['CARD','EWALLET','BANK'], true)) $method = 'CARD';

$cart = $_SESSION['cart'] ?? [];
if (!$cart) finish('Empty cart', 'Add an item before checkout.');

$pdo = cart_db();
$ids = array_map('intval', array_keys($cart));
$marks = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id,name,price,stock FROM products WHERE id IN ($marks)");
$stmt->execute($ids);
$products = $stmt->fetchAll();
$total = 0.0; $validItems = [];
foreach ($products as $p) {
    $qty = min((int)$cart[$p['id']], (int)$p['stock']);
    if ($qty > 0) {
        $total += $qty * (float)$p['price'];
        $validItems[] = ['product'=>$p,'qty'=>$qty];
    }
}
if (!$validItems || $total <= 0) finish('Checkout error', 'No valid items were found.');

/* Persist order before calling the independent payment service. */
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("INSERT INTO orders (customer_name,total_amount,status) VALUES (?,?,'PENDING_PAYMENT')");
    $stmt->execute([$customer, number_format($total,2,'.','')]);
    $orderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,quantity,unit_price) VALUES (?,?,?,?,?)');
    foreach ($validItems as $i) {
        $itemStmt->execute([$orderId,$i['product']['id'],$i['product']['name'],$i['qty'],$i['product']['price']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    finish('Order error', 'Could not save the order.');
}

$orderStatus = 'PAYMENT_PENDING';
$gatewayTransactionId = null;
$resultTitle = 'Payment pending';
$resultMessage = 'The order was saved, but the payment result could not be confirmed. Please check the gateway before retrying.';

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/shopping-cart/checkout.php')), '/');
$projectBase = preg_replace('#/shopping-cart$#', '', $scriptDir);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$apiUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $projectBase . '/payment-gateway/api/payments.php';

// Change to FAILED or PENDING when running the deterministic lab tests.
$testOutcome = 'SUCCESS';

$payload = [
    'order_id' => $orderId,
    'amount' => (float)number_format($total, 2, '.', ''),
    'customer_name' => $customer,
    'payment_method' => $method,
    'idempotency_key' => 'cart-order-' . $orderId,
    'outcome' => $testOutcome,
];

$response = null;
$integrationError = null;

if (!function_exists('curl_init')) {
    $integrationError = 'PHP cURL is not enabled.';
} else {
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $rawResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($rawResponse === false) {
        $integrationError = 'Gateway request failed: ' . $curlError;
    } elseif ($httpCode < 200 || $httpCode >= 300) {
        $integrationError = 'Gateway returned HTTP ' . $httpCode . '.';
    } else {
        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            $integrationError = 'Gateway returned invalid JSON.';
        } else {
            $response = $decoded;
        }
    }
}

if ($response !== null) {
    $gatewayOrderId = (int)($response['order_id'] ?? 0);
    $gatewayStatus = (string)($response['status'] ?? '');
    $gatewayTransactionId = trim((string)($response['transaction_id'] ?? ''));

    if (($response['success'] ?? false) !== true || $gatewayOrderId !== $orderId || $gatewayTransactionId === '') {
        $integrationError = 'Gateway response did not match the saved order.';
        $gatewayTransactionId = null;
    } elseif ($gatewayStatus === 'SUCCESS') {
        $orderStatus = 'PAID';
        $resultTitle = 'Payment successful';
        $resultMessage = 'Order #' . $orderId . ' was paid. Gateway transaction: ' . $gatewayTransactionId . '.';
        unset($_SESSION['cart']);
    } elseif ($gatewayStatus === 'FAILED') {
        $orderStatus = 'PAYMENT_FAILED';
        $resultTitle = 'Payment failed';
        $resultMessage = 'Order #' . $orderId . ' was saved, but the simulated payment failed. Gateway transaction: ' . $gatewayTransactionId . '.';
    } elseif ($gatewayStatus === 'PENDING') {
        $orderStatus = 'PAYMENT_PENDING';
        $resultTitle = 'Payment pending';
        $resultMessage = 'Order #' . $orderId . ' is awaiting payment confirmation. Gateway transaction: ' . $gatewayTransactionId . '.';
    } else {
        $integrationError = 'Gateway returned an unknown payment status.';
        $gatewayTransactionId = null;
    }
}

try {
    $pdo->beginTransaction();

    if ($orderStatus === 'PAID') {
        $stockStmt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
        foreach ($validItems as $item) {
            $qty = (int)$item['qty'];
            $productId = (int)$item['product']['id'];
            $stockStmt->execute([$qty, $productId, $qty]);

            if ($stockStmt->rowCount() !== 1) {
                throw new RuntimeException('Not enough stock was available to complete the paid order.');
            }
        }
    }

    $stmt = $pdo->prepare('UPDATE orders SET status = ?, gateway_transaction_id = ? WHERE id = ?');
    $stmt->execute([$orderStatus, $gatewayTransactionId, $orderId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $resultTitle = 'Checkout error';
    $resultMessage = 'Order #' . $orderId . ' was saved, but the checkout update could not be completed. Please check the order before retrying.';
}

if ($integrationError !== null) {
    $resultMessage = 'Order #' . $orderId . ' was saved, but payment could not be confirmed: ' . $integrationError . ' Verify the payment gateway before retrying.';
}

finish($resultTitle, $resultMessage);


/*
 * STUDENT TASK: Integrate the shopping cart with the
 * independent payment gateway.
 *
 * Requirements:
 * 1. Prepare the payment request payload.
 * 2. Send an HTTP POST request with JSON using PHP cURL.
 * 3. Handle the gateway's HTTP response.
 * 4. Validate the response and match the order ID.
 * 5. Handle SUCCESS, FAILED, and PENDING outcomes.
 * 6. Handle timeouts and invalid responses safely.
 * 7. Save the gateway transaction ID and update the
 *    order status in the shopping cart database.
 * 8. Prevent duplicate payment requests using an
 *    idempotency key.
 *
 * Read payment-gateway/README-API.md for the API contract.
 */