<?php
include __DIR__ . '/admin-auth.php';
require_roles_api(['owner', 'sales']);
include __DIR__ . '/db-connection.php';
include __DIR__ . '/tenant-context.php';
header('Cache-Control: no-store');

function receipt_phone(string $value): string {
    $value = trim($value);
    if (!preg_match('/^\+?[0-9 ()-]+$/D', $value)) return '';
    $digits = preg_replace('/\D/', '', $value);
    if (strpos($digits, '00') === 0) $digits = substr($digits, 2);
    if (strlen($digits) === 10 && $digits[0] === '0') $digits = '233' . substr($digits, 1);
    return preg_match('/^[1-9][0-9]{7,14}$/D', $digits) ? $digits : '';
}

function receipt_money($value): string {
    return 'GHS ' . number_format((float)$value, 2, '.', ',');
}

try {
    ensure_multitenant_schema($conn);
    $businessId = current_business_id();
    $orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
    if ($businessId <= 0 || !$orderId || $orderId <= 0) {
        http_response_code(400);
        throw new RuntimeException('Invalid order.');
    }
    $stmt = $conn->prepare('SELECT * FROM orders WHERE id = ? AND business_id = ? LIMIT 1');
    $stmt->bind_param('ii', $orderId, $businessId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) {
        http_response_code(404);
        throw new RuntimeException('Order not found for this store.');
    }
    $business = tenant_fetch_business_by_id($conn, $businessId);
    $lines = [
        (string)($business['business_name'] ?? 'Store'),
        'ORDER RECEIPT #' . $orderId,
        'Date: ' . $order['created_at'],
        'Customer: ' . $order['customer_name'],
        'Order status: ' . $order['status'],
        'Payment status: ' . ($order['payment_status'] ?? 'unpaid'),
        'Payment method: ' . str_replace('_', ' ', (string)($order['payment_method'] ?? 'cod')),
        '', 'Items:'
    ];
    $stmt = $conn->prepare('SELECT product_name, quantity, price FROM order_items WHERE order_id = ? AND business_id = ? ORDER BY id');
    $stmt->bind_param('ii', $orderId, $businessId);
    $stmt->execute();
    $items = $stmt->get_result();
    while ($item = $items->fetch_assoc()) {
        $lines[] = $item['quantity'] . ' x ' . $item['product_name'] . ' @ ' . receipt_money($item['price']) . ' = ' . receipt_money($item['quantity'] * $item['price']);
    }
    $stmt->close();
    $discount = max(0, round((float)$order['subtotal'] + (float)$order['tax'] + (float)$order['shipping'] - (float)$order['total'], 2));
    $lines = array_merge($lines, [
        '', 'Subtotal: ' . receipt_money($order['subtotal']),
        'Discount: ' . receipt_money($discount),
        'Tax: ' . receipt_money($order['tax']),
        'Delivery Fee: ' . receipt_money($order['shipping']),
        'TOTAL: ' . receipt_money($order['total'])
    ]);
    if (!empty($order['payment_reference'])) $lines[] = 'Payment reference: ' . $order['payment_reference'];
    if (!empty($business['contact_number'])) $lines[] = 'Store contact: ' . $business['contact_number'];
    $lines[] = 'Thank you for shopping with us!';
    $phone = receipt_phone((string)($order['customer_phone'] ?? ''));
    // With no valid customer number, WhatsApp lets the sender choose a contact.
    $url = 'https://wa.me/' . $phone . '?text=' . rawurlencode(implode("\n", $lines));
    header('Location: ' . $url, true, 302);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(500);
    error_log('whatsapp-receipt.php: ' . $e->getMessage());
    header('Content-Type: text/plain; charset=utf-8');
    echo http_response_code() === 404 ? 'Order not found for this store.' : 'Unable to prepare the WhatsApp receipt. Return to Sales History and try again.';
} finally {
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
}
